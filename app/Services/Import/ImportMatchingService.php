<?php

namespace App\Services\Import;

use App\Models\Child;
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
     *
     * $onlyBatchId: por defecto la búsqueda es automática y system-wide (cualquier
     * batch de la fuente opuesta, alguna vez subido — ver "Retroactive matching" en
     * la clase). Si se provee, se restringe a las filas de ESE batch puntual — usado
     * por rematchAgainst() cuando un operador elige a mano contra qué otra hoja
     * comparar, en vez de confiar en la búsqueda automática.
     */
    public function match(ImportRow $row, ?string $onlyBatchId = null): MatchResult
    {
        // 'health' no tiene una fuente "opuesta" con la que emparejarse (no es un
        // evento de nacimiento seguido de una inscripción, como civil_registry↔education).
        // Si llegamos hasta acá es porque matchChild() YA buscó un niño existente por
        // DNI+nombre+apellido y no encontró ninguno con 3/3 señales — no autocompletamos
        // creando un niño nuevo sin más: queda "sin coincidencia" para que un operador lo
        // cree a mano, igual que civil_registry/education cuando no encuentran contraparte.
        // Evita duplicar niños cuando la hoja de salud se procesa antes que las otras y
        // el DNI de esa hoja viene mal cargado (matchChild no lo hubiera detectado).
        if ($row->isFromHealth()) {
            return new MatchResult(
                0,
                'none',
                null,
                'No se encontró ningún niño existente por DNI, nombre o apellido. Revisar antes de crear uno nuevo.'
            );
        }

        $oppositeSource = $row->isFromCivilRegistry() ? 'education' : 'civil_registry';

        // Candidatos exactos: mismo name_normalized + misma fecha de nacimiento
        $exactCandidates = $this->queryOppositeSource($row, $oppositeSource, $onlyBatchId)
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
        $accentCandidates = $this->queryOppositeSource($row, $oppositeSource, $onlyBatchId)
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
     *
     * $onlyBatchId restringe la búsqueda a un batch puntual en vez del system-wide
     * "cualquier batch de $oppositeSource" — ver match().
     */
    private function queryOppositeSource(ImportRow $row, string $oppositeSource, ?string $onlyBatchId = null)
    {
        $query = $onlyBatchId
            ? ImportRow::where('batch_id', $onlyBatchId)
            : ImportRow::whereHas('batch', fn($q) => $q->where('source', $oppositeSource));

        return $query
            ->where('birth_date', $row->birth_date)
            ->whereNotIn('status', ['matched', 'skipped'])
            ->where('id', '!=', $row->id);
    }

    /**
     * Re-corre match() para una fila puntual, restringido a un batch elegido a mano
     * por el operador (en vez de la búsqueda automática system-wide), cuando quiere
     * comparar contra una hoja distinta a la que el sistema sugirió.
     *
     * Nunca se auto-resuelve: aunque el resultado sea 100% de confianza, queda para
     * que el operador confirme con el botón habitual — el criterio de "cuándo crear
     * el registro" no cambia solo porque el operador guió la búsqueda a mano.
     */
    public function rematchAgainst(ImportRow $row, string $onlyBatchId): MatchResult
    {
        $result = $this->match($row, $onlyBatchId);

        if ($result->confidence === 100) {
            return new MatchResult(99, $result->type, $result->matchedRowId, $result->notes);
        }

        return $result;
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
            $previousMatchedRowId = $row->matched_row_id;

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

            // Si esta fila ya tenía OTRA contraparte de un match anterior (ej. un
            // rematchAgainst() manual que la reemplazó), esa vieja contraparte queda
            // "casada" con una fila que ya no la referencia — la liberamos.
            if ($previousMatchedRowId && $previousMatchedRowId !== $result->matchedRowId) {
                ImportRow::where('id', $previousMatchedRowId)
                    ->where('status', 'partial_match')
                    ->update([
                        'status'         => 'no_match',
                        'matched_row_id' => null,
                        'match_notes'    => 'La fila con la que coincidía fue recomparada contra otra hoja. Sin coincidencia.',
                    ]);
            }
        });
    }

    // ─── Matching contra Child existente (DNI + nombre + apellido) ────────────────

    /**
     * Busca a qué Child existente corresponde una fila de 'education'/'health'
     * (las únicas fuentes que traen DNI propio del niño), combinando 3 señales
     * independientes para tolerar que UNA de las tres venga mal cargada:
     *
     *   - dni:      $row->dni_hash coincide con el dni_hash del candidato
     *   - nombre:   first_name coincide (case-insensitive, tolera diferencia de tilde)
     *   - apellido: last_name coincide (ídem)
     *
     * El DNI pesa más que el nombre/apellido: dos personas distintas pueden
     * compartir nombre y apellido (nada raro en español), pero no DNI. Por eso
     * "DNI coincide, nombre y apellido no" (tier 2) se considera MÁS confiable
     * que "nombre y apellido coinciden, DNI no" (tier 1), aunque en cantidad de
     * señales sea al revés (1 señal vs. 2).
     *
     * Solo confianza 100 (las 3 señales, candidato único) resuelve automáticamente.
     * Cualquier otra combinación — incluida empate entre varios candidatos —
     * queda como sugerencia para que un operador confirme a qué niño corresponde.
     */
    public function matchChild(ImportRow $row): ChildMatchResult
    {
        $firstName = $row->getRawField('first_name');
        $lastName  = $row->getRawField('last_name');

        $candidates = Child::query()
            ->where(function ($query) use ($row) {
                $hasSignal = false;

                if ($row->dni_hash) {
                    $query->orWhere('dni_hash', $row->dni_hash);
                    $hasSignal = true;
                }
                if ($row->name_normalized) {
                    $query->orWhere('name_normalized', $row->name_normalized);
                    $hasSignal = true;
                }
                if ($row->name_no_accents) {
                    $query->orWhere('name_no_accents', $row->name_no_accents);
                    $hasSignal = true;
                }

                if (! $hasSignal) {
                    $query->whereRaw('1 = 0');
                }
            })
            ->get();

        if ($candidates->isEmpty()) {
            return new ChildMatchResult(0, null, null, 'No hay ningún niño existente cuyo DNI o nombre coincidan.');
        }

        $scored = $candidates->map(function (Child $child) use ($row, $firstName, $lastName) {
            $dni      = $row->dni_hash !== null && $child->dni_hash === $row->dni_hash;
            $nombre   = $firstName !== null && $this->namesMatch($firstName, $child->first_name);
            $apellido = $lastName !== null && $this->namesMatch($lastName, $child->last_name);
            [$tier, $confidence] = $this->childMatchTier($dni, $nombre, $apellido);

            return [
                'child'      => $child,
                'tier'       => $tier,
                'confidence' => $confidence,
                'dni'        => $dni,
                'nombre'     => $nombre,
                'apellido'   => $apellido,
            ];
        })->filter(fn (array $s) => $s['tier'] !== -1);

        if ($scored->isEmpty()) {
            return new ChildMatchResult(0, null, null, 'No hay ningún niño existente cuyo DNI o nombre coincidan.');
        }

        $maxTier = $scored->max('tier');
        $best = $scored->filter(fn (array $s) => $s['tier'] === $maxTier);

        if ($best->count() > 1) {
            $names = $best->map(fn (array $s) => "{$s['child']->first_name} {$s['child']->last_name}")->implode(', ');
            // Un empate nunca es 100% automático, aunque el tier máximo sea el de
            // "las 3 señales" (implicaría más de un Child con el mismo DNI — revisar a mano).
            return new ChildMatchResult(
                min($best->first()['confidence'], 90),
                null,
                null,
                "Hay más de un niño candidato igual de probable: {$names}. Revisión manual necesaria para elegir."
            );
        }

        $winner = $best->first();

        if ($maxTier === self::CHILD_TIER_ALL_MATCH) {
            return new ChildMatchResult(
                100,
                $winner['child']->id,
                $winner['child']->id,
                'DNI, nombre y apellido coinciden con un niño ya existente.'
            );
        }

        $mismatched = collect(['dni' => 'DNI', 'nombre' => 'nombre', 'apellido' => 'apellido'])
            ->reject(fn (string $label, string $key) => $winner[$key])
            ->implode(', ');

        return new ChildMatchResult(
            $winner['confidence'],
            null,
            $winner['child']->id,
            "Coincide parcialmente con {$winner['child']->first_name} {$winner['child']->last_name}: no coincide {$mismatched}. Revisar antes de vincular."
        );
    }

    private const CHILD_TIER_ALL_MATCH = 4;

    /**
     * @return array{0: int, 1: int} [tier, confianza] — tier más alto = más confiable.
     * El DNI pesa más que las 2 señales de nombre juntas: "solo DNI" (tier 2, 75%)
     * rankea por encima de "nombre y apellido sin DNI" (tier 1, 65%).
     */
    private function childMatchTier(bool $dni, bool $nombre, bool $apellido): array
    {
        return match (true) {
            $dni && $nombre && $apellido   => [self::CHILD_TIER_ALL_MATCH, 100],
            $dni && ($nombre xor $apellido) => [3, 85],
            $dni                             => [2, 75],
            $nombre && $apellido            => [1, 65],
            $nombre || $apellido            => [0, 35],
            default                          => [-1, 0], // filtrado por el caller (sin ninguna señal)
        };
    }

    private function namesMatch(string $a, string $b): bool
    {
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));

        return $a === $b || $this->stripAccents($a) === $this->stripAccents($b);
    }

    private function stripAccents(string $value): string
    {
        $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
        return preg_replace('/\p{Mn}/u', '', $decomposed);
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
     *
     * Descarta todo lo que no sea dígito (puntos, espacios, guiones, letras de
     * verificación pegadas al final tipo "44458061Z") — mismo criterio que
     * User::normalizeDni(). El DNI argentino es siempre numérico: cualquier
     * caracter no numérico es ruido de carga, no parte del número. Sin esto,
     * dos filas del mismo DNI con formato distinto generan hashes distintos y
     * el matching por DNI nunca los encuentra como la misma persona.
     */
    public static function hashDni(string $dni): string
    {
        $normalized = preg_replace('/\D/', '', $dni);
        return hash('sha256', $normalized);
    }
}
