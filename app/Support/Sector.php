<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Sector — única fuente de verdad del "sector" de una institución/efector
 * (institutions.type) y de una prestación (child_services.sector,
 * service_types.sector). Sector y tipo de institución son LA MISMA COSA.
 *
 * Las claves internas son las históricas de institutions.type a propósito: las
 * usan las Policies, el RLS de niños (ChildController) y el login institucional
 * ('educacion' → vista escolar, 'salud' → vista de salud). Lo que cambió son las
 * etiquetas, alineadas a la nomenclatura de los organismos:
 *
 *   salud             → Salud
 *   educacion         → Cuidado y educación
 *   desarrollo_social → Protección social
 *   recreacion        → Recreación, deporte y cultura
 *   otro              → Otro
 *
 * 'justicia' queda solo por compatibilidad con instituciones ya cargadas: se
 * sigue aceptando y mostrando, pero no se ofrece para instituciones nuevas.
 */
final class Sector
{
    /** Sectores ofrecidos (instituciones nuevas, prestaciones, archivos). */
    public const LABELS = [
        'salud'             => 'Salud',
        'educacion'         => 'Cuidado y educación',
        'desarrollo_social' => 'Protección social',
        'recreacion'        => 'Recreación, deporte y cultura',
        'otro'              => 'Otro',
    ];

    /** Valores heredados: válidos en la base pero no se ofrecen para cargas nuevas. */
    private const LEGACY_LABELS = [
        'justicia' => 'Justicia',
    ];

    /**
     * Variantes aceptadas en archivos, ya normalizadas con normalize()
     * (minúsculas, sin tildes, solo letras separadas por un espacio).
     */
    private const SYNONYMS = [
        'salud'                          => 'salud',
        'cuidado y educacion'            => 'educacion',
        'cuidado educacion'              => 'educacion',
        'educacion y cuidado'            => 'educacion',
        'educacion'                      => 'educacion',
        'cuidado'                        => 'educacion',
        'proteccion social'              => 'desarrollo_social',
        'proteccion'                     => 'desarrollo_social',
        'desarrollo social'              => 'desarrollo_social',
        'recreacion deporte y cultura'   => 'recreacion',
        'recreacion deporte cultura'     => 'recreacion',
        'recreacion'                     => 'recreacion',
        'deporte'                        => 'recreacion',
        'deportes'                       => 'recreacion',
        'cultura'                        => 'recreacion',
        'deporte y cultura'              => 'recreacion',
        'recreacion y deporte'           => 'recreacion',
        'otro'                           => 'otro',
        'otros'                          => 'otro',
    ];

    /** @return list<string> sectores ofrecidos para cargas nuevas */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    /** @return list<string> todo lo válido en institutions.type (incluye heredados) */
    public static function institutionTypes(): array
    {
        return [...array_keys(self::LABELS), ...array_keys(self::LEGACY_LABELS)];
    }

    public static function label(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return self::LABELS[$key] ?? self::LEGACY_LABELS[$key] ?? ucfirst($key);
    }

    /** @return list<array{value: string, label: string}> para desplegables */
    public static function options(): array
    {
        return collect(self::LABELS)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * Texto del archivo → clave del sector, o null si no se reconoce. Acepta
     * también la propia clave ("desarrollo_social").
     */
    public static function fromText(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (array_key_exists(trim($value), self::LABELS)) {
            return trim($value);
        }

        return self::SYNONYMS[self::normalize($value)] ?? null;
    }

    private static function normalize(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z]+/', ' ')
            ->trim()
            ->toString();
    }
}
