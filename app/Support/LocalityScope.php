<?php

namespace App\Support;

use App\Models\Institution;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * LocalityScope — filtro global "ámbito" que el admin/coordinador elige desde
 * el sidebar del frontend y que acota los listados de TODA la plataforma a las
 * instituciones de una localidad fija.
 *
 * El frontend lo manda en cada request como header `X-Locality-Scope`
 * (ver frontend/src/api/client.ts). Se sigue aceptando también el query param
 * histórico `institution_locality` de GET /children.
 *
 * IMPORTANTE: es una lente de VISTA, no un control de acceso. La seguridad
 * sigue en RLS + Policies: por eso solo se respeta para quien ya ve todo
 * (canBypassRls) y cualquier valor desconocido se ignora. Se aplica endpoint
 * por endpoint, a propósito NO como Global Scope de Eloquent: el matching de
 * importaciones, los jobs de la cola, las validaciones unique y los detalles
 * (children/{id}, etc.) tienen que seguir viendo todo el sistema.
 *
 * provincia/departamento desambiguan nombres repetidos entre provincias
 * (ej. "SAN JUSTO" existe como departamento/localidad en Buenos Aires,
 * Córdoba y Santa Fe).
 */
final class LocalityScope
{
    public const HEADER = 'X-Locality-Scope';

    private const FILTERS = [
        'san_justo' => ['province' => 'Santa Fe', 'department' => 'SAN JUSTO', 'locality' => 'SAN JUSTO', 'label' => 'San Justo (Santa Fe)'],
        'uriburu'   => ['province' => 'La Pampa', 'department' => 'CATRILO', 'locality' => 'URIBURU', 'label' => 'Uriburu (La Pampa)'],
    ];

    /**
     * Clave del ámbito activo en este request, o null = sin filtro (todo el
     * sistema). Solo admin/coordinador: el resto ya está acotado por RLS.
     */
    public static function fromRequest(Request $request): ?string
    {
        $user = $request->user();

        if (! $user || ! $user->canBypassRls()) {
            return null;
        }

        $key = $request->header(self::HEADER) ?: $request->query('institution_locality');

        return is_string($key) && isset(self::FILTERS[$key]) ? $key : null;
    }

    public static function label(string $key): string
    {
        return self::FILTERS[$key]['label'];
    }

    /** Acota una query de Institution a las de la localidad del ámbito. */
    public static function applyToInstitutions(Builder $query, string $key): Builder
    {
        $filter = self::FILTERS[$key];

        return $query->whereHas('locality', fn ($q) => $q
            ->where('name', 'ilike', $filter['locality'])
            ->whereHas('department', fn ($dq) => $dq
                ->where('name', 'ilike', $filter['department'])
                ->whereHas('province', fn ($pq) => $pq->where('name', 'ilike', $filter['province']))));
    }

    /**
     * Acota una query de Child a los niños con registro (educativo o de salud)
     * en CUALQUIER institución de la localidad del ámbito, sin importar el sector.
     */
    public static function applyToChildren(Builder $query, string $key): Builder
    {
        $institutionIds = self::institutionIdsSubquery($key);

        return $query->where(fn ($q) => $q
            ->whereHas('educationRecord', fn ($eq) => $eq->whereIn('institution_id', $institutionIds))
            ->orWhereHas('healthRecord', fn ($hq) => $hq->whereIn('institution_id', $institutionIds)));
    }

    /** Subquery `select id from institutions where ...` para usar en whereIn. */
    public static function institutionIdsSubquery(string $key): Builder
    {
        return self::applyToInstitutions(Institution::query(), $key)->select('institutions.id');
    }

    /** @return list<string> IDs de las instituciones del ámbito (incluye dadas de baja). */
    public static function institutionIds(string $key): array
    {
        return self::applyToInstitutions(Institution::withTrashed(), $key)->pluck('id')->all();
    }
}
