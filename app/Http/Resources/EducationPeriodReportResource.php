<?php

namespace App\Http\Resources;

use App\Models\EducationRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * EducationPeriodReportResource — Un reporte bimestral del registro educativo.
 */
class EducationPeriodReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'year'           => $this->year,
            'bimester'       => $this->bimester,

            'level'          => $this->level,
            'level_label'    => $this->level ? EducationRecord::levelLabel($this->level) : null,
            'grade'          => $this->grade,
            'grade_label'    => ($this->level && $this->grade)
                ? EducationRecord::gradeLabel($this->level, $this->grade)
                : null,

            'is_enrolled'    => $this->is_enrolled,
            'absences_count' => $this->absences_count,
            'present_days'   => $this->present_days,
            'total_days'     => $this->total_days,
            'summary'        => $this->summary,

            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
        ];
    }
}
