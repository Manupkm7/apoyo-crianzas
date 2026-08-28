<?php

namespace App\Services\Import;

/**
 * Resultado inmutable de un intento de matching entre dos filas de importación.
 *
 * Niveles de confianza:
 *   100 — nombre exacto (case-insensitive, accent-sensitive) + fecha de nacimiento exacta
 *    85 — nombre coincide solo al normalizar tildes + fecha exacta
 *    75 — múltiples candidatos con el mismo nombre y fecha → ambigüedad
 *     0 — sin coincidencia
 *
 * Ninguna confianza — ni siquiera 100 — crea un registro solo: el sistema NUNCA
 * da de alta un niño sin que un operador lo confirme a mano. La confianza solo
 * decide qué tan "obvia" se ve la sugerencia en la pantalla de revisión.
 */
readonly class MatchResult
{
    public function __construct(
        public readonly int $confidence,
        public readonly string $type,          // 'exact' | 'accent_conflict' | 'ambiguous' | 'none'
        public readonly ?string $matchedRowId, // ID del ImportRow contraparte (si existe)
        public readonly string $notes,         // explicación legible del resultado
    ) {}

    public function toStatus(): string
    {
        return $this->confidence > 0 ? 'partial_match' : 'no_match';
    }
}
