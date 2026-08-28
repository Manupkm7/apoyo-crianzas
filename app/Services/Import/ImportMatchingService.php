<?php

namespace App\Services\Import;

use App\Models\Child;
use App\Models\ImportRow;
use Illuminate\Support\Facades\DB;
use Normalizer;

/**
 * Motor de matching entre filas de registro civil y educación.
 *
 * Prioridad de señales (pedido explícito del cliente, 2026-08-26): el DNI es
 * la señal más importante, después el nombre, y la fecha de nacimiento es la
 * MENOS importante — nunca es obligatoria para que una fila aparezca como
 * posible coincidencia. Concretamente:
 *
 *   1. DNI primero (ver matchByDni()): si el DNI coincide con una única fila
 *      de la fuente opuesta, esa es la sugerencia — aunque el nombre no
 *      coincida en absoluto (confianza 45, la más baja de las "sí hay
 *      sugerencia") ni la fecha de nacimiento esté cargada o coincida.
 *      Cuantas más señales se sumen (nombre, fecha), más sube la confianza,
 *      hasta 100 cuando las 3 coinciden.
 *
 *   2. Sin DNI (o sin coincidencia de DNI): se busca por nombre —
 *      MATCH EXACTO (name_normalized igual, 90 — 100 si además coincide la
 *      fecha) o CONFLICTO DE TILDE (name_no_accents igual, name_normalized
 *      distinto; 75 — 85 con fecha). AMBIGÜEDAD (75) si hay más de un
 *      candidato con el mismo nombre.
 *
 *   3. SIN MATCH (confianza 0): no se encontró nada por DNI ni por nombre.
 *
 * NINGÚN nivel de confianza — ni siquiera 100 — crea o vincula un registro
 * solo: el operador que sube el archivo tiene que aprobar cada niño que se
 * da de alta, siempre, con un click explícito en la pantalla de revisión.
 * La confianza solo decide qué tan "obvia" se ve la sugerencia ahí.
 *
 * El matching es RETROACTIVO: cuando llega educación después de que registro civil
 * ya fue importado, las filas de civil en 'no_match' se actualizan a 'partial_match'
 * con la nueva sugerencia (ver applyMatch()).
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
        // DNI+nombre+apellido y no encontró ninguno — no autocompletamos creando un
        // niño nuevo sin más: queda "sin coincidencia" para que un operador lo cree a
        // mano, igual que civil_registry/education cuando no encuentran contraparte.
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

        // 1) DNI primero — es la señal más confiable: ni el nombre ni la fecha de
        //    nacimiento son obligatorios para que la fila aparezca como posible
        //    coincidencia (ver matchByDni()).
        if ($row->dni_hash) {
            $dniResult = $this->matchByDni($row, $oppositeSource, $onlyBatchId);
            if ($dniResult !== null) {
                return $dniResult;
            }
        }

        // 2) Sin DNI (o sin coincidencia de DNI): matching por nombre. La fecha de
        //    nacimiento YA NO es un filtro obligatorio para encontrar el candidato
        //    — si además coincide suma confianza, si no coincide o no está no
        //    descarta la fila (ver nameMatchResult()).
        $exactCandidates = $this->queryOppositeSource($row, $oppositeSource, $onlyBatchId)
            ->where('name_normalized', $row->name_normalized)
            ->get();

        if ($exactCandidates->count() === 1) {
            return $this->nameMatchResult($row, $exactCandidates->first(), exact: true);
        }

        if ($exactCandidates->count() > 1) {
            return new MatchResult(
                75,
                'ambiguous',
                null,
                "Se encontraron {$exactCandidates->count()} registros con nombre \"{$row->name_normalized}\". Se requiere revisión manual para identificar cuál corresponde."
            );
        }

        // Sin match exacto → buscar candidatos con diferencia de tilde
        $accentCandidates = $this->queryOppositeSource($row, $oppositeSource, $onlyBatchId)
            ->where('name_no_accents', $row->name_no_accents)
            ->get();

        if ($accentCandidates->count() === 1) {
            return $this->nameMatchResult($row, $accentCandidates->first(), exact: false);
        }

        if ($accentCandidates->count() > 1) {
            return new MatchResult(
                75,
                'ambiguous',
                null,
                "Se encontraron {$accentCandidates->count()} registros similares (diferencia de tildes). Se requiere revisión manual."
            );
        }

        return new MatchResult(
            0,
            'none',
            null,
            'No se encontró ningún registro con el mismo DNI ni con nombre similar en la fuente opuesta.'
        );
    }

    /**
     * Matching por DNI: la señal más fuerte, evaluada ANTES que el nombre. Un
     * único candidato con el mismo dni_hash siempre es una sugerencia válida,
     * aunque el nombre no coincida en absoluto ni la fecha de nacimiento esté
     * cargada — la confianza sube con cada señal adicional que se sume, pero
     * ninguna de las dos (nombre, fecha) es requisito para llegar hasta acá.
     *
     * Devuelve null cuando no hay ningún candidato por DNI, para que match()
     * siga con el matching por nombre.
     */
    private function matchByDni(ImportRow $row, string $oppositeSource, ?string $onlyBatchId): ?MatchResult
    {
        $dniCandidates = $this->queryOppositeSourceByDni($row, $oppositeSource, $onlyBatchId)->get();

        if ($dniCandidates->isEmpty()) {
            return null;
        }

        if ($dniCandidates->count() > 1) {
            return new MatchResult(
                45,
                'ambiguous',
                null,
                "El DNI coincide con {$dniCandidates->count()} filas de la fuente opuesta (con nombres distintos). Se requiere revisión manual para identificar cuál corresponde."
            );
        }

        $candidate = $dniCandidates->first();
        $nameExact = $row->name_normalized !== null && $row->name_normalized === $candidate->name_normalized;
        $nameAccent = ! $nameExact && $row->name_no_accents !== null && $row->name_no_accents === $candidate->name_no_accents;
        $birthMatches = $row->birth_date !== null && $candidate->birth_date !== null && $row->birth_date->equalTo($candidate->birth_date);

        $confidence = match (true) {
            $nameExact && $birthMatches  => 100,
            $nameExact                   => 90,
            $nameAccent && $birthMatches => 85,
            $nameAccent                  => 75,
            $birthMatches                => 60,
            default                      => 45, // solo el DNI coincide, nada más
        };

        $notes = match (true) {
            $nameExact  => "DNI y nombre coinciden con \"{$candidate->name_normalized}\"" . ($birthMatches ? ' y la fecha de nacimiento.' : ', pero la fecha de nacimiento no coincide o no está cargada.'),
            $nameAccent => "DNI coincide y el nombre coincide salvo por tildes: \"{$row->name_normalized}\" (este registro) vs \"{$candidate->name_normalized}\" (contraparte).",
            default     => "DNI coincide con \"{$candidate->name_normalized}\" en la fuente opuesta, pero el nombre no. Revisar si es la misma persona antes de vincular.",
        };

        return new MatchResult($confidence, 'dni', $candidate->id, $notes);
    }

    /**
     * Arma el MatchResult para un candidato encontrado por nombre (exacto o con
     * diferencia de tildes) — la fecha de nacimiento suma confianza si coincide,
     * pero su ausencia o desacuerdo no descarta la fila.
     */
    private function nameMatchResult(ImportRow $row, ImportRow $candidate, bool $exact): MatchResult
    {
        $birthMatches = $row->birth_date !== null && $candidate->birth_date !== null && $row->birth_date->equalTo($candidate->birth_date);

        $confidence = $exact
            ? ($birthMatches ? 100 : 90)
            : ($birthMatches ? 85 : 75);

        $notes = $exact
            ? ('Coincidencia exacta por nombre completo' . ($birthMatches ? ' y fecha de nacimiento.' : '; la fecha de nacimiento no coincide o no está cargada.'))
            : ("Posible coincidencia pero con diferencia de tildes: \"{$row->name_normalized}\" (este registro) vs \"{$candidate->name_normalized}\" (contraparte)."
                . ($birthMatches ? ' La fecha de nacimiento sí coincide.' : ' Revisar si es la misma persona.'));

        return new MatchResult($confidence, $exact ? 'exact' : 'accent_conflict', $candidate->id, $notes);
    }

    /**
     * Base de la consulta: filas de la fuente opuesta que aún no fueron
     * asignadas a otra fila. La fecha de nacimiento NO es un filtro acá — es
     * una señal de menor peso, no un requisito (ver nameMatchResult()/matchByDni()).
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
            ->whereNotIn('status', ['matched', 'skipped'])
            ->where('id', '!=', $row->id);
    }

    /** Igual que queryOppositeSource() pero filtrando por DNI en vez de por nombre. */
    private function queryOppositeSourceByDni(ImportRow $row, string $oppositeSource, ?string $onlyBatchId = null)
    {
        return $this->queryOppositeSource($row, $oppositeSource, $onlyBatchId)
            ->where('dni_hash', $row->dni_hash);
    }

    /**
     * Re-corre match() para una fila puntual, restringido a un batch elegido a mano
     * por el operador (en vez de la búsqueda automática system-wide), cuando quiere
     * comparar contra una hoja distinta a la que el sistema sugirió.
     */
    public function rematchAgainst(ImportRow $row, string $onlyBatchId): MatchResult
    {
        return $this->match($row, $onlyBatchId);
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

    // ─── Matching contra Child existente (DNI + nombre + apellido + fecha) ────────

    /**
     * Busca a qué Child existente corresponde una fila de importación, combinando
     * 4 señales independientes para tolerar que alguna venga mal cargada:
     *
     *   - dni:      $row->dni_hash coincide con el dni_hash del candidato (peso 45)
     *   - nombre:   first_name coincide (case-insensitive, tolera tilde) (peso 25)
     *   - apellido: last_name coincide (ídem) (peso 20)
     *   - fecha:    birth_date coincide (peso 10)
     *
     * La confianza es la SUMA de los pesos de las señales que coinciden (100 si
     * las 4 coinciden). El DNI pesa más que el nombre y el apellido juntos, y la
     * fecha de nacimiento es la señal más débil — nunca es obligatoria: su
     * ausencia o desacuerdo no descarta un candidato, solo no suma.
     *
     * Ninguna combinación resuelve sola — ni siquiera con las 4 señales: siempre
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
            return new ChildMatchResult(0, null, 'No hay ningún niño existente cuyo DNI o nombre coincidan.');
        }

        $scored = $candidates->map(function (Child $child) use ($row, $firstName, $lastName) {
            $dni      = $row->dni_hash !== null && $child->dni_hash === $row->dni_hash;
            $nombre   = $firstName !== null && $this->namesMatch($firstName, $child->first_name);
            $apellido = $lastName !== null && $this->namesMatch($lastName, $child->last_name);
            $fecha    = $row->birth_date !== null && $child->birth_date !== null && $row->birth_date->equalTo($child->birth_date);

            return [
                'child'      => $child,
                'confidence' => $this->childMatchScore($dni, $nombre, $apellido, $fecha),
                'dni'        => $dni,
                'nombre'     => $nombre,
                'apellido'   => $apellido,
                'fecha'      => $fecha,
            ];
        })->filter(fn (array $s) => $s['confidence'] > 0);

        if ($scored->isEmpty()) {
            return new ChildMatchResult(0, null, 'No hay ningún niño existente cuyo DNI o nombre coincidan.');
        }

        $maxConfidence = $scored->max('confidence');
        $best = $scored->filter(fn (array $s) => $s['confidence'] === $maxConfidence);

        if ($best->count() > 1) {
            $names = $best->map(fn (array $s) => "{$s['child']->first_name} {$s['child']->last_name}")->implode(', ');
            // Un empate nunca es 100% "seguro", aunque la confianza máxima sea 100
            // (implicaría más de un Child con el mismo DNI+nombre+apellido+fecha).
            return new ChildMatchResult(
                min($maxConfidence, 90),
                null,
                "Hay más de un niño candidato igual de probable: {$names}. Revisión manual necesaria para elegir."
            );
        }

        $winner = $best->first();

        if ($maxConfidence === 100) {
            return new ChildMatchResult(
                100,
                $winner['child']->id,
                'DNI, nombre, apellido y fecha de nacimiento coinciden con un niño ya existente.'
            );
        }

        $mismatched = collect(['dni' => 'DNI', 'nombre' => 'nombre', 'apellido' => 'apellido', 'fecha' => 'fecha de nacimiento'])
            ->reject(fn (string $label, string $key) => $winner[$key])
            ->implode(', ');

        return new ChildMatchResult(
            $winner['confidence'],
            $winner['child']->id,
            "Coincide parcialmente con {$winner['child']->first_name} {$winner['child']->last_name}: no coincide {$mismatched}. Revisar antes de vincular."
        );
    }

    private const CHILD_SIGNAL_DNI = 45;
    private const CHILD_SIGNAL_NOMBRE = 25;
    private const CHILD_SIGNAL_APELLIDO = 20;
    private const CHILD_SIGNAL_FECHA = 10;

    /**
     * Confianza = suma de los pesos de las señales que coinciden (ver docblock
     * de matchChild()). 45+25+20+10 = 100 cuando coinciden las 4.
     */
    private function childMatchScore(bool $dni, bool $nombre, bool $apellido, bool $fecha): int
    {
        return ($dni ? self::CHILD_SIGNAL_DNI : 0)
            + ($nombre ? self::CHILD_SIGNAL_NOMBRE : 0)
            + ($apellido ? self::CHILD_SIGNAL_APELLIDO : 0)
            + ($fecha ? self::CHILD_SIGNAL_FECHA : 0);
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
