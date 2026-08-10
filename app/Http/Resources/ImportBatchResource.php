<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\InstitutionResource;
use App\Http\Resources\UserResource;

class ImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'source'            => $this->source,
            'source_label'      => $this->source === 'civil_registry' ? 'Registro Civil' : 'Educación',
            'status'            => $this->status,
            'original_filename' => $this->original_filename,

            'rows' => [
                'total'      => $this->total_rows,
                'matched'    => $this->matched_rows,
                'partial'    => $this->partial_rows,
                'no_match'   => $this->no_match_rows,
                'errors'     => $this->error_rows,
                'pending_review' => $this->partial_rows + $this->no_match_rows,
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