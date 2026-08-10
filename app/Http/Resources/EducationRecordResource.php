<?php

namespace App\Http\Resources;

use App\Models\EducationRecord;
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

            'level'          => $this->level,
            'level_label'    => $this->level ? EducationRecord::levelLabel($this->level) : null,
            'grade'          => $this->grade,
            'grade_label'    => ($this->level && $this->grade)
                ? EducationRecord::gradeLabel($this->level, $this->grade)
                : null,

            'absences_count' => $this->absences_count,

            'attendance_present_days' => $this->attendance_present_days,
            'attendance_total_days'   => $this->attendance_total_days,
            'attendance_period_label' => $this->attendance_period_label,

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
