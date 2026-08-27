<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * HealthPeriodReportResource — Un reporte bimestral del registro de salud.
 */
class HealthPeriodReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'year'                    => $this->year,
            'bimester'                => $this->bimester,

            'healthy_checkup_current' => $this->healthy_checkup_current,
            'vaccines_current'        => $this->vaccines_current,
            'last_checkup_date'       => $this->last_checkup_date?->toDateString(),
            'weight_kg'               => $this->weight_kg,
            'height_cm'               => $this->height_cm,
            'summary'                 => $this->summary,

            'created_at'              => $this->created_at?->toISOString(),
            'updated_at'              => $this->updated_at?->toISOString(),
        ];
    }
}
