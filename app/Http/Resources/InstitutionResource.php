<?php

namespace App\Http\Resources;

use App\Support\AdministrativeDependency;
use App\Support\Sector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * InstitutionResource — Controla qué datos de una institución se envían al frontend.
 *
 * Este "transformador" actúa como filtro: el modelo puede tener más campos en la base de datos,
 * pero este recurso decide exactamente qué se muestra en la respuesta JSON.
 * Así se evita exponer campos internos como created_by, updated_by, etc.
 */
class InstitutionResource extends JsonResource
{
    /**
     * Incluir las articulaciones (efectores con los que trabaja). Se activa en el
     * detalle / alta / edición — en el listado costaría una consulta por fila.
     */
    public bool $includeArticulations = false;

    public function withArticulations(): static
    {
        $this->includeArticulations = true;
        return $this;
    }

    /**
     * Convierte el modelo Institution en un array JSON para la respuesta.
     *
     * Incluye una etiqueta legible del tipo de institución (type_label)
     * para que el frontend no tenga que traducir los valores internos.
     *
     * Si la relación 'users' fue cargada con withCount(), también incluye users_count.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            // ID_EFECTOR: número correlativo legible generado por la base
            'code'        => $this->code,
            'name'        => $this->name,
            // type = sector (misma cosa, ver App\Support\Sector)
            'type'        => $this->type,
            'type_label'  => Sector::label($this->type),

            // Ficha del efector
            'administrative_dependency'       => $this->administrative_dependency,
            'administrative_dependency_label' => AdministrativeDependency::label($this->administrative_dependency),
            'program_area' => $this->whenLoaded('programArea', fn () => $this->programArea ? [
                'id'   => $this->programArea->id,
                'name' => $this->programArea->name,
            ] : null),
            'beneficiaries' => $this->beneficiaries,
            'observations'  => $this->observations,
            'articulations' => $this->when($this->includeArticulations, fn () => $this->resource
                ->articulatedInstitutions()
                ->map(fn ($i) => [
                    'id'         => $i->id,
                    'code'       => $i->code,
                    'name'       => $i->name,
                    'type'       => $i->type,
                    'type_label' => Sector::label($i->type),
                    'is_active'  => (bool) $i->is_active,
                ])
                ->values()),

            'address'     => $this->address,
            'phone'       => $this->phone,
            'locality_id' => $this->locality_id,
            // Cadena completa de jurisdicción — se expone aplanada (no anidada) para
            // que el frontend pueda precargar los 3 desplegables en cascada del
            // formulario sin tener que resolver la cadena locality->department->province.
            'locality'    => $this->whenLoaded('locality', fn () => $this->locality ? [
                'id'   => $this->locality->id,
                'name' => $this->locality->name,
            ] : null),
            'department'  => $this->whenLoaded('locality', fn () => $this->locality?->relationLoaded('department') && $this->locality->department ? [
                'id'   => $this->locality->department->id,
                'name' => $this->locality->department->name,
            ] : null),
            'province'    => $this->whenLoaded('locality', fn () => $this->locality?->relationLoaded('department')
                && $this->locality->department?->relationLoaded('province')
                && $this->locality->department->province ? [
                'id'   => $this->locality->department->province->id,
                'name' => $this->locality->department->province->name,
            ] : null),
            'is_active'   => $this->is_active,

            // Configuración de niveles educativos — solo relevante cuando type === 'educacion'
            'offers_jardin'     => $this->offers_jardin,
            'offers_primario'   => $this->offers_primario,
            'primario_years'    => $this->primario_years,
            'offers_secundario' => $this->offers_secundario,
            'secundario_years'  => $this->secundario_years,

            // Se incluye solo si se cargó el conteo de usuarios (withCount('users'))
            'users_count' => $this->when(
                isset($this->users_count),
                $this->users_count
            ),

            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }
}
