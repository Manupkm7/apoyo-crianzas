<?php

namespace App\Http\Resources;

use App\Support\Sector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ServiceTypeResource — una prestación del catálogo.
 */
class ServiceTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'sector'       => $this->sector,
            'sector_label' => Sector::label($this->sector),
            'description'  => $this->description,
            'is_mandatory' => (bool) $this->is_mandatory,
            'is_active'    => (bool) $this->is_active,
            // Solo cuando se pidió withCount('childServices') (listado del catálogo).
            'usage_count'  => $this->whenCounted('childServices'),
            'created_at'   => $this->created_at?->toISOString(),
            'updated_at'   => $this->updated_at?->toISOString(),
        ];
    }
}
