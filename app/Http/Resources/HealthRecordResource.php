<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * HealthRecordResource — Controla qué datos de un registro de salud se envían al frontend.
 *
 * Incluye todos los campos del dominio de salud.
 * El nombre de la institución se incluye solo cuando la relación está cargada.
 */
class HealthRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                       => $this->id,
            'child_id'                 => $this->child_id,
            'health_center_name'       => $this->health_center_name,
            'healthy_checkup_current'  => $this->healthy_checkup_current,
            'vaccines_current'         => $this->vaccines_current,
            'last_checkup_date'        => $this->last_checkup_date?->toDateString(),
            'observations'             => $this->observations,

            // Se incluye el nombre de la institución solo cuando se cargó la relación
            'institution'              => $this->whenLoaded('institution', fn () => [
                'id'   => $this->institution->id,
                'name' => $this->institution->name,
            ]),

            'created_at'               => $this->created_at?->toISOString(),
            'updated_at'               => $this->updated_at?->toISOString(),
        ];
    }
}
