<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EducationRecordResource — Controla qué datos de un registro educativo se envían al frontend.
 *
 * Incluye todos los campos del dominio educativo.
 * El nombre de la institución se incluye solo cuando la relación está cargada.
 */
class EducationRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'child_id'       => $this->child_id,
            'school_name'    => $this->school_name,
            'grade_or_year'  => $this->grade_or_year,
            'absences_count' => $this->absences_count,
            'is_enrolled'    => $this->is_enrolled,
            'observations'   => $this->observations,

            // Se incluye el nombre de la institución solo cuando se cargó la relación
            'institution'    => $this->whenLoaded('institution', fn () => [
                'id'   => $this->institution->id,
                'name' => $this->institution->name,
            ]),

            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
        ];
    }
}
