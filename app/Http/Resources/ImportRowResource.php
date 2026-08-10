<?php

namespace App\Http\Resources;

use App\Http\Resources\ChildResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ImportRowResource — datos de una fila de importación para la cola de revisión manual.
 *
 * Descifra raw_data para mostrar nombre/apellido en su casing original.
 * No expone DNIs en ningún campo de la respuesta — solo los datos necesarios para
 * que el operador decida si dos registros corresponden a la misma persona.
 */
class ImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // raw_data es un JSON cifrado; el cast 'encrypted' del modelo lo descifra al acceder.
        $raw = json_decode($this->raw_data ?? '{}', true) ?: [];

        return [
            'id'               => $this->id,
            'status'           => $this->status,
            'source'           => $this->whenLoaded('batch', fn () => $this->batch->source),
            'source_label'     => $this->whenLoaded('batch', fn () =>
                $this->batch->source === 'civil_registry' ? 'Registro Civil' : 'Educación'
            ),

            // Nombre y fecha en su forma original (casing del archivo)
            'first_name'       => $raw['first_name'] ?? null,
            'last_name'        => $raw['last_name'] ?? null,
            'birth_date'       => $this->birth_date?->toDateString(),

            // Extra contextual según la fuente (no sensible)
            'school_name'          => $raw['school_name'] ?? null,
            'birth_establishment'  => $raw['birth_establishment'] ?? null,

            // Resultado del matching
            'match_confidence' => $this->match_confidence,
            'match_notes'      => $this->match_notes,
            'file_line_number' => $this->file_line_number,

            // Contraparte de la otra fuente (si la hay)
            'matched_row' => $this->whenLoaded('matchedRow', function () {
                if (! $this->matchedRow) {
                    return null;
                }
                $matchedRaw = json_decode($this->matchedRow->raw_data ?? '{}', true) ?: [];
                return [
                    'id'               => $this->matchedRow->id,
                    'first_name'       => $matchedRaw['first_name'] ?? null,
                    'last_name'        => $matchedRaw['last_name'] ?? null,
                    'birth_date'       => $this->matchedRow->birth_date?->toDateString(),
                    'school_name'      => $matchedRaw['school_name'] ?? null,
                    'match_confidence' => $this->matchedRow->match_confidence,
                ];
            }),

            // Niño vinculado (si ya fue resuelto)
            'child' => $this->whenLoaded('child', fn () =>
                $this->child ? new ChildResource($this->child) : null
            ),

            // Quién y cuándo resolvió esta fila manualmente
            'resolved_by' => $this->when($this->resolved_at !== null, fn () => [
                'user_id'     => $this->resolved_by,
                'resolved_at' => $this->resolved_at?->toISOString(),
            ]),

            'error_message' => $this->when($this->status === 'error', $this->error_message),
        ];
    }
}