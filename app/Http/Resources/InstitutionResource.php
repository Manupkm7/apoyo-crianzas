<?php

namespace App\Http\Resources;

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
            'name'        => $this->name,
            'type'        => $this->type,
            'type_label'  => $this->typeLabel(),
            'address'     => $this->address,
            'phone'       => $this->phone,
            'is_active'   => $this->is_active,

            // Se incluye solo si se cargó el conteo de usuarios (withCount('users'))
            'users_count' => $this->when(
                isset($this->users_count),
                $this->users_count
            ),

            'created_at'  => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Convierte el valor interno del tipo en una etiqueta legible en español.
     *
     * Por ejemplo: 'desarrollo_social' → 'Desarrollo Social'
     */
    private function typeLabel(): string
    {
        return match ($this->type) {
            'salud'            => 'Salud',
            'educacion'        => 'Educación',
            'desarrollo_social'=> 'Desarrollo Social',
            'justicia'         => 'Justicia',
            'otro'             => 'Otro',
            default            => ucfirst($this->type),
        };
    }
}
