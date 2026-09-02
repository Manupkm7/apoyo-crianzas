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
        $counts = $this->rows()
            ->selectRaw("
                SUM(CASE WHEN status = 'matched'         THEN 1 ELSE 0 END) as matched,
                SUM(CASE WHEN status = 'partial_match'   THEN 1 ELSE 0 END) as partial,
                SUM(CASE WHEN status = 'no_match'        THEN 1 ELSE 0 END) as no_match,
                SUM(CASE WHEN status = 'manual_resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN status = 'skipped'         THEN 1 ELSE 0 END) as skipped,
                SUM(CASE WHEN status = 'error'           THEN 1 ELSE 0 END) as errors
            ")
            ->first();

        return [
            'id'                => $this->id,
            'source'            => $this->source,
            'source_label'      => match ($this->source) {
                'civil_registry' => 'Registro Civil',
                'health'         => 'Salud',
                default          => 'Educación',
            },
            'status'            => $this->status,
            'original_filename' => $this->original_filename,
            'sheet_name'        => $this->sheet_name,
            // Comparte el mismo valor entre todos los batches creados a partir del
            // mismo archivo subido (una hoja cada uno) — el frontend lo usa para
            // agruparlos visualmente. Ver ImportController::siblings()/rematchBatch().
            'storage_path'      => $this->storage_path,

            'rows' => [
                'total'          => $this->total_rows,
                'matched'        => (int) $counts->matched,
                'partial'        => (int) $counts->partial,
                'no_match'       => (int) $counts->no_match,
                'resolved'       => (int) $counts->resolved,
                'skipped'        => (int) $counts->skipped,
                'errors'         => (int) $counts->errors,
                'pending_review' => (int) $counts->partial + (int) $counts->no_match,
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