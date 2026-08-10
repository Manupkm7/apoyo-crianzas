<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * HealthObservationResource — Una entrada de la bitácora de observaciones de salud.
 *
 * El adjunto nunca se sirve como URL pública: 'attachment_url' apunta a un
 * endpoint autenticado que verifica que el usuario tenga acceso al registro
 * de salud antes de entregar el archivo.
 */
class HealthObservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'body'             => $this->body,
            'author_name'      => $this->whenLoaded('author', fn () => $this->author?->name),
            'has_attachment'   => (bool) $this->attachment_path,
            'attachment_name'  => $this->attachment_original_name,
            'attachment_url'   => $this->attachment_path
                ? route('health-observations.attachment', $this->id)
                : null,
            'created_at'       => $this->created_at?->toISOString(),
        ];
    }
}
