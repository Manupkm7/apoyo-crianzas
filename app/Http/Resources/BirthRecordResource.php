<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BirthRecordResource — Controla qué datos de un registro de nacimiento se envían al frontend.
 *
 * Solo llega a este resource quien ya pasó BirthRecordPolicy::view() (admin/coordinador),
 * así que se incluyen los campos descifrados de la madre/padre y el domicilio.
 */
class BirthRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'child_id' => $this->child_id,

            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'birth_date' => $this->birth_date?->toDateString(),

            'mother_name' => $this->mother_name,
            'mother_dni'  => $this->mother_dni,

            'father_name' => $this->father_name,
            'father_dni'  => $this->father_dni,

            'address'             => $this->address,
            'birth_establishment' => $this->birth_establishment,
            'observations'        => $this->observations,

            // Se incluye el nombre de la institución solo cuando se cargó la relación
            'institution' => $this->whenLoaded('institution', fn () => $this->institution ? [
                'id'   => $this->institution->id,
                'name' => $this->institution->name,
            ] : null),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
