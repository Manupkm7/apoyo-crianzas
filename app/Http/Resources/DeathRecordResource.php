<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DeathRecordResource — Controla qué datos de un registro de defunción se envían al frontend.
 *
 * Solo llega a este resource quien ya pasó DeathRecordPolicy::view() (admin/coordinador).
 * cause_of_death se incluye igual que el resto: el filtro fuerte ya ocurrió en la Policy
 * y en el activity log (nunca se audita), no hace falta ocultarlo dos veces acá.
 */
class DeathRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'child_id' => $this->child_id,

            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'death_date' => $this->death_date?->toDateString(),

            'child_dni'  => $this->child_dni,
            'mother_dni' => $this->mother_dni,

            'cause_of_death' => $this->cause_of_death,
            'observations'   => $this->observations,

            'institution' => $this->whenLoaded('institution', fn () => $this->institution ? [
                'id'   => $this->institution->id,
                'name' => $this->institution->name,
            ] : null),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
