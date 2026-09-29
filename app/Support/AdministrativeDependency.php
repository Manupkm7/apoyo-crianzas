<?php

namespace App\Support;

/**
 * Dependencia administrativa de un efector (institutions.administrative_dependency).
 * Lista cerrada — el CHECK de la base usa las mismas claves.
 */
final class AdministrativeDependency
{
    public const LABELS = [
        'estatal'     => 'Estatal',
        'privado'     => 'Privado',
        'comunitario' => 'Comunitario',
        'otros'       => 'Otros',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(?string $key): ?string
    {
        return $key !== null ? (self::LABELS[$key] ?? null) : null;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return collect(self::LABELS)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }
}
