<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ServiceDateRange — filtro global "Fecha de prestaciones" (desde / hasta) que
 * el usuario elige desde el sidebar del frontend, junto al ámbito por localidad
 * (ver LocalityScope).
 *
 * Las prestaciones no tienen una fecha puntual sino un PERÍODO (TRIM22026 =
 * 1/4/2026 a 30/6/2026, BIM32026 = 1/5/2026 a 30/6/2026). Una prestación entra
 * si su período se SUPERPONE con el rango elegido: con desde = hasta = 15/5/2026
 * aparecen TRIM22026 y BIM32026.
 *
 * Viaja en cada request como headers X-Services-From / X-Services-To (Y-m-d,
 * cualquiera de los dos puede faltar = rango abierto). Es una lente de vista:
 * solo achica resultados que el usuario ya puede ver, por eso aplica a todos
 * los roles. Un valor inválido o un rango invertido se ignoran.
 */
final class ServiceDateRange
{
    public const HEADER_FROM = 'X-Services-From';
    public const HEADER_TO   = 'X-Services-To';

    /** @return array{from: ?CarbonImmutable, to: ?CarbonImmutable}|null  null = sin filtro */
    public static function fromRequest(Request $request): ?array
    {
        $from = self::parseDate($request->header(self::HEADER_FROM));
        $to   = self::parseDate($request->header(self::HEADER_TO));

        if ($from === null && $to === null) {
            return null;
        }

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * Acota una query de ChildService a las prestaciones cuyo período se
     * superpone con el rango: empieza antes del fin del rango y termina después
     * del inicio. El fin del período es period_start + duración (exclusivo).
     */
    public static function applyToServices(Builder $query, array $range): Builder
    {
        if ($range['to'] !== null) {
            $query->where('child_services.period_start', '<=', $range['to']->toDateString());
        }

        if ($range['from'] !== null) {
            $query->whereRaw(
                "child_services.period_start + (CASE WHEN child_services.period_type = ? THEN INTERVAL '2 months' ELSE INTERVAL '3 months' END) > ?",
                [ServicePeriod::BIMESTRE, $range['from']->toDateString()]
            );
        }

        return $query;
    }

    private static function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat acepta desbordes (2026-02-31 → 3/3): se exige ida y vuelta exacta.
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
