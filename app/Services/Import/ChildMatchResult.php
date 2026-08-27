<?php

namespace App\Services\Import;

/**
 * Resultado de ImportMatchingService::matchChild(): identifica (o sugiere) a
 * qué Child existente corresponde una fila de importación, combinando 3
 * señales independientes (DNI, nombre, apellido).
 *
 * Confianza 100 = las 3 señales coinciden con un único candidato → auto.
 * Cualquier otro caso (incluida la ambigüedad entre varios candidatos)
 * queda con confianza < 100 y solo una SUGERENCIA — nunca se vincula solo.
 */
readonly class ChildMatchResult
{
    public function __construct(
        public readonly int $confidence,      // 0-100
        public readonly ?string $childId,     // solo si confidence === 100
        public readonly ?string $suggestedChildId,
        public readonly string $notes,
    ) {}

    public function isAutomatic(): bool
    {
        return $this->confidence === 100 && $this->childId !== null;
    }
}
