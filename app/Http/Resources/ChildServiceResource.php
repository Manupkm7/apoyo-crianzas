<?php

namespace App\Http\Resources;

use App\Support\ServicePeriod;
use App\Support\Sector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ChildServiceResource — una prestación por período de un niño.
 */
class ChildServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'child_id'       => $this->child_id,
            // Solo en la grilla general (GET /child-services)
            'child'          => $this->whenLoaded('child', fn () => $this->child ? [
                'id'         => $this->child->id,
                'first_name' => $this->child->first_name,
                'last_name'  => $this->child->last_name,
            ] : null),

            'service_type'   => $this->whenLoaded('serviceType', fn () => $this->serviceType ? [
                'id'           => $this->serviceType->id,
                'name'         => $this->serviceType->name,
                'is_mandatory' => (bool) $this->serviceType->is_mandatory,
            ] : null),

            'sector'         => $this->sector,
            'sector_label'   => Sector::label($this->sector),

            // Efector
            'institution'    => $this->whenLoaded('institution', fn () => $this->institution ? [
                'id'   => $this->institution->id,
                'name' => $this->institution->name,
                'type' => $this->institution->type,
            ] : null),

            'year'           => $this->year,
            'period_type'    => $this->period_type,
            'period_number'  => $this->period_number,
            'period_start'   => $this->period_start?->toDateString(),
            // "2026-trimestre-1" — para agrupar/filtrar en el frontend
            'period_key'     => $this->periodKey(),
            // "TRIM12026" — mismo código que usan los archivos
            'period_code'    => ServicePeriod::code($this->period_type, $this->period_number, $this->year),
            'period_label'   => ServicePeriod::label($this->period_type, $this->period_number, $this->year),

            'service_number' => $this->service_number,
            'observations'   => $this->observations,
            'has_alert'      => (bool) $this->has_alert,
            'alert_flagged_at' => $this->alert_flagged_at?->toISOString(),

            // 'import' si vino de una carga masiva, 'manual' si se cargó a mano
            'origin'         => $this->import_row_id ? 'import' : 'manual',

            'created_at'     => $this->created_at?->toISOString(),
            'updated_at'     => $this->updated_at?->toISOString(),
        ];
    }
}
