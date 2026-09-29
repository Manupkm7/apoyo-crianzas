<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Período de una prestación: año + tipo (trimestre | bimestre) + número.
 *
 * Los organismos lo informan en la columna "Fecha" con un código compacto:
 * "TRIM12026" = trimestre 1 de 2026, "BIM32026" = bimestre 3 de 2026. parse()
 * tolera variantes habituales de carga ("TRIM 1 2026", "T1-2026",
 * "Trimestre 1 2026", "2026-T1", "2026 BIM 3"...).
 *
 * start() devuelve el primer día del período — se guarda en
 * child_services.period_start para ordenar y encontrar el último período
 * informado sin importar si es trimestre o bimestre.
 */
final class ServicePeriod
{
    public const TRIMESTRE = 'trimestre';
    public const BIMESTRE  = 'bimestre';

    public const TYPES = [self::TRIMESTRE, self::BIMESTRE];

    /** Máximo número de período por tipo. */
    private const MAX = [self::TRIMESTRE => 4, self::BIMESTRE => 6];

    /** Meses que dura cada tipo de período. */
    private const MONTHS = [self::TRIMESTRE => 3, self::BIMESTRE => 2];

    /** Prefijo corto para el código (TRIM12026 / BIM32026). */
    private const CODE_PREFIX = [self::TRIMESTRE => 'TRIM', self::BIMESTRE => 'BIM'];

    /**
     * @return array{type: string, number: int, year: int}|null  null si el texto no se reconoce.
     */
    public static function parse(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtoupper(trim($value));
        $normalized = strtr($normalized, ['Í' => 'I', 'É' => 'E', 'º' => '', '°' => '']);
        $normalized = str_replace(['TRIMESTRE', 'BIMESTRE'], ['TRIM', 'BIM'], $normalized);
        $normalized = preg_replace('/[\s\-_.\/]+/', '', $normalized);

        // Tipo + número + año: TRIM12026, T12026, BIM32026, B32026
        if (preg_match('/^(TRIM|TRI|T|BIM|BI|B)(\d)(\d{4})$/', $normalized, $m)) {
            return self::build($m[1], (int) $m[2], (int) $m[3]);
        }

        // Año + tipo + número: 2026TRIM1, 2026T1, 2026BIM3
        if (preg_match('/^(\d{4})(TRIM|TRI|T|BIM|BI|B)(\d)$/', $normalized, $m)) {
            return self::build($m[2], (int) $m[3], (int) $m[1]);
        }

        return null;
    }

    private static function build(string $prefix, int $number, int $year): ?array
    {
        $type = str_starts_with($prefix, 'T') ? self::TRIMESTRE : self::BIMESTRE;

        if (! self::isValid($type, $number, $year)) {
            return null;
        }

        return ['type' => $type, 'number' => $number, 'year' => $year];
    }

    public static function isValid(string $type, int $number, int $year): bool
    {
        return isset(self::MAX[$type])
            && $number >= 1
            && $number <= self::MAX[$type]
            && $year >= 2000
            && $year <= 2100;
    }

    public static function maxNumber(string $type): int
    {
        return self::MAX[$type];
    }

    /** Primer día del período (ej. trimestre 2 → 1 de abril). */
    public static function start(string $type, int $number, int $year): CarbonImmutable
    {
        $month = ($number - 1) * self::MONTHS[$type] + 1;

        return CarbonImmutable::create($year, $month, 1)->startOfDay();
    }

    /** Código compacto, igual al que usan los archivos: TRIM12026 / BIM32026. */
    public static function code(string $type, int $number, int $year): string
    {
        return self::CODE_PREFIX[$type] . $number . $year;
    }

    /** Etiqueta legible: "Trimestre 1 · 2026". */
    public static function label(string $type, int $number, int $year): string
    {
        return ($type === self::TRIMESTRE ? 'Trimestre' : 'Bimestre') . " {$number} · {$year}";
    }
}
