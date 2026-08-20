<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'status'            => $this->status,
            'original_filename' => $this->original_filename,

            'rows' => [
                'total'        => $this->total_rows,
                'created'      => $this->created_rows,
                'needs_review' => $this->needs_review_rows,
                'skipped'      => $this->skipped_rows,
                'errors'       => $this->error_rows,
            ],

            // Solo se muestra si falló
            'error_message' => $this->when($this->status === 'failed', $this->error_message),

            'institution' => $this->whenLoaded('institution', fn () =>
                $this->institution ? new InstitutionResource($this->institution) : null
            ),

            'uploaded_by' => $this->whenLoaded('uploader', fn () =>
                $this->uploader ? new UserResource($this->uploader) : null
            ),

            'started_at'  => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'created_at'  => $this->created_at?->toISOString(),
        ];
    }
}
