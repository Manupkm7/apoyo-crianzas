<?php

namespace App\Services\Import;

/**
 * Resultado inmutable de un intento de matching entre dos filas de importación.
 *
 * Niveles de confianza:
 *   100 — nombre exacto (case-insensitive, accent-sensitive) + fecha de nacimiento exacta → match automático
 *    85 — nombre coincide solo al normalizar tildes + fecha exacta → revisión manual
 *    75 — múltiples candidatos con el mismo nombre y fecha → ambigüedad, revisión manual
 *     0 — sin coincidencia
 */
readonly class MatchResult
{
    public function __construct(
        public readonly int $confidence,
        public readonly string $type,          // 'exact' | 'accent_conflict' | 'ambiguous' | 'none'
        public readonly ?string $matchedRowId, // ID del ImportRow contraparte (si existe)
        public readonly string $notes,         // explicación legible del resultado
    ) {}

    public function isAutomatic(): bool
    {
        return $this->confidence === 100;
    }

    public function needsReview(): bool
    {
        return $this->confidence > 0 && $this->confidence < 100;
    }

    public function hasNoMatch(): bool
    {
        return $this->confidence === 0;
    }

    public function toStatus(): string
    {
        return match (true) {
            $this->isAutomatic()  => 'matched',
            $this->needsReview()  => 'partial_match',
            default               => 'no_match',
        };
    }
}
