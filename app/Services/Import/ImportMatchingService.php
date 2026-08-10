<?php

namespace App\Services\Import;

use App\Models\ImportRow;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Motor de matching entre filas de registro civil y educación.
 *
 * Estrategia de comparación de nombres:
 *
 *   1. MATCH EXACTO (confianza 100)
 *      name_normalized igual en ambas fuentes → mismo nombre, misma fecha.
 *      name_normalized = mb_strtolower(nombre + apellido), conservando tildes.
 *      Ejemplo: "matías rodríguez" == "matías rodríguez" ✓
 *
 *   2. CONFLICTO DE TILDE (confianza 85) → revisión manual
 *      name_no_accents igual pero name_normalized distinto.
 *      El operador debe confirmar si son la misma persona.
 *      Ejemplo: "matías rodríguez" vs "matias rodriguez" → diferencia de tilde detectada.
 *
 *   3. AMBIGÜEDAD (confianza 75) → revisión manual
 *      Más de un candidato con el mismo name_normalized y fecha.
 *      El operador debe elegir cuál corresponde.
 *
 *   4. SIN MATCH (confianza 0)
 *      No se encontró ningún candidato en la fuente opuesta.
 *
 * El matching es RETROACTIVO: cuando llega educación después de que registro civil
 * ya fue importado, las filas de civil en estado 'no_match' se actualizan a 'matched'.
 */
class ImportMatchingService
{
    /**
     * Calcula el mejor match para una fila dada buscando en la fuente opuesta.
     *
     * Solo busca en filas que estén en estado pending o no_match para evitar
     * asignar la misma contraparte a múltiples filas.
     */
    public function match(ImportRow $row): MatchResult
    {
        $oppositeSource = $row->isFromCivilRegistry() ? 'education' : 'civil_registry';

        // Candidatos exactos: mismo name_normalized + misma fecha de nacimiento
        $exactCandidates = $this->queryOppositeSource($row, $oppositeSource)
            ->where('name_normalized', $row->name_normalized)
            ->get();

        if ($exactCandidates->count() === 1) {
            return new MatchResult(
                100,
                'exact',
                $exactCandidates->first()->id,
                "Coincidencia exacta por nombre completo y fecha de nacimiento."
            );
        }

        if ($exactCandidates->count() > 1) {
            return new MatchResult(
                75,
                'ambiguous',
                null,
                "Se encontraron {$exactCandidates->count()} registros con nombre \"{$row->name_normalized}\" y la misma fecha. Se requiere revisión manual para identificar cuál corresponde."
            );
        }

        // Sin match exacto → buscar candidatos con diferencia de tilde
        $accentCandidates = $this->queryOppositeSource($row, $oppositeSource)
            ->where('name_no_accents', $row->name_no_accents)
            ->get();

        if ($accentCandidates->count() === 1) {
            $candidate = $accentCandidates->first();
            return new MatchResult(
                85,
                'accent_conflict',
                $candidate->id,
                "Posible coincidencia pero con diferencia de tildes: \"{$row->name_normalized}\" (este registro) vs \"{$candidate->name_normalized}\" (contraparte). Revisar si es la misma persona."
            );
        }

        if ($accentCandidates->count() > 1) {
            return new MatchResult(
                75,
                'ambiguous',
                null,
                "Se encontraron {$accentCandidates->count()} registros similares (diferencia de tildes) con la misma fecha. Se requiere revisión manual."
            );
        }

        return new MatchResult(
            0,
            'none',
            null,
            "No se encontró ningún registro con nombre similar y misma fecha de nacimiento en la fuente opuesta."
        );
    }

    /**
     * Base de la consulta: filas de la fuente opuesta con la misma fecha de nacimiento
     * que aún no fueron asignadas a otra fila.
     *
     * Las filas en estado 'matched' ya tienen pareja; 'skipped' fueron descartadas.
     */
    private function queryOppositeSource(ImportRow $row, string $oppositeSource)
    {
        return ImportRow::whereHas('batch', fn($q) => $q->where('source', $oppositeSource))
            ->where('birth_date', $row->birth_date)
            ->whereNotIn('status', ['matched', 'skipped'])
            ->where('id', '!=', $row->id);
    }

    /**
     * Aplica el resultado de un match: actualiza ambas filas en una transacción atómica.
     *
     * Si la contraparte estaba en 'no_match' (importada antes, sin pareja en ese momento),
     * también se actualiza retroactivamente.
     */
    public function applyMatch(ImportRow $row, MatchResult $result): void
    {
        DB::transaction(function () use ($row, $result) {
            $row->update([
                'status'           => $result->toStatus(),
                'match_confidence' => $result->confidence,
                'match_notes'      => $result->notes,
                'matched_row_id'   => $result->matchedRowId,
            ]);

            // Actualizar la contraparte si existe (matching bidireccional)
            if ($result->matchedRowId) {
                ImportRow::where('id', $result->matchedRowId)->update([
                    'status'           => $result->toStatus(),
                    'match_confidence' => $result->confidence,
                    'match_notes'      => $result->notes,
                    'matched_row_id'   => $row->id,
                ]);
            }
        });
    }

    // ─── Utilidades de normalización ─────────────────────────────────────────────

    /**
     * Convierte nombre + apellido a minúsculas conservando tildes y ñ.
     * Ejemplo: "Matías RODRÍGUEZ" → "matías rodríguez"
     */
    public static function normalizeName(string $firstName, string $lastName): string
    {
        return mb_strtolower(trim($firstName) . ' ' . trim($lastName));
    }

    /**
     * Igual que normalizeName pero elimina todos los diacríticos.
     * Ejemplo: "matías rodríguez" → "matias rodriguez"
     *
     * Algoritmo:
     *   1. Descomponer a FORM_D: cada carácter acentuado → carácter base + combining mark
     *   2. Eliminar las combining marks (categoría Unicode Mn)
     *   Requiere la extensión PHP intl (incluida en la imagen Docker del proyecto).
     */
    public static function normalizeNameNoAccents(string $firstName, string $lastName): string
    {
        $full = mb_strtolower(trim($firstName) . ' ' . trim($lastName));
        $decomposed = Normalizer::normalize($full, Normalizer::FORM_D);
        return preg_replace('/\p{Mn}/u', '', $decomposed);
    }

    /**
     * Calcula el SHA-256 de un DNI para almacenamiento en dni_hash.
     * Normaliza eliminando puntos y espacios antes de hashear.
     */
    public static function hashDni(string $dni): string
    {
        $normalized = preg_replace('/[\s.]/', '', $dni);
        return hash('sha256', $normalized);
    }
}
