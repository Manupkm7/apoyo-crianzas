<?php

namespace App\Services\Import;

/**
 * Resultado de ImportMatchingService::matchChild(): identifica (o sugiere) a
 * qué Child existente corresponde una fila de importación, combinando 4
 * señales independientes (DNI, nombre, apellido, fecha de nacimiento).
 *
 * Ninguna confianza — ni siquiera 100 — vincula sola: el sistema nunca crea
 * ni vincula un niño sin que un operador lo confirme a mano. La confianza
 * solo decide qué tan destacada se ve la sugerencia en pantalla.
 */
readonly class ChildMatchResult
{
    public function __construct(
        public readonly int $confidence,      // 0-100
        public readonly ?string $suggestedChildId,
        public readonly string $notes,
    ) {}
}
