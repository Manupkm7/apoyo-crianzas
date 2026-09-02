<?php

namespace App\Http\Resources;

use App\Http\Resources\ChildResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ImportRowResource — datos de una fila de importación para la cola de revisión manual.
 *
 * Descifra raw_data y expone todos los campos relevantes del archivo original: el
 * operador tiene que poder identificar a quién corresponde la fila sin abrir el
 * Excel. Endpoint restringido a admin/coordinador (reportes.ver | importaciones.gestionar),
 * que ya tienen acceso al DNI de niños/representantes en el resto del sistema.
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
            'source_label'     => $this->whenLoaded('batch', fn () => match ($this->batch->source) {
                'civil_registry' => 'Registro Civil',
                'health'         => 'Salud',
                default          => 'Educación',
            }),

            // Nombre y fecha en su forma original (casing del archivo)
            'first_name'       => $raw['first_name'] ?? null,
            'last_name'        => $raw['last_name'] ?? null,
            // Respaldo: si raw_data no trae nombre (ej. filas de antes de un fix de parseo),
            // esta columna siempre estuvo bien calculada desde el archivo, aunque sin el
            // casing/tildes originales exactos.
            'name_fallback'    => $this->name_normalized,
            'birth_date'       => $this->birth_date?->toDateString(),
            'dni'              => $raw['dni'] ?? null,

            // Registro civil
            'mother_name'         => $raw['mother_name'] ?? null,
            'mother_dni'          => $raw['mother_dni'] ?? null,
            'father_name'         => $raw['father_name'] ?? null,
            'father_dni'          => $raw['father_dni'] ?? null,
            'address'             => $raw['address'] ?? null,
            'birth_establishment' => $raw['birth_establishment'] ?? null,

            // Educación
            'school_name'    => $raw['school_name'] ?? null,
            'grade_or_year'  => $raw['grade_or_year'] ?? null,

            // Salud
            'health_center_name'      => $raw['health_center_name'] ?? null,
            'healthy_checkup_current' => $raw['healthy_checkup_current'] ?? null,
            'vaccines_current'        => $raw['vaccines_current'] ?? null,
            'last_checkup_date'       => $raw['last_checkup_date'] ?? null,
            'observations'            => $raw['observations'] ?? null,

            // Todas las columnas tal como venían en el archivo original (cabecera => valor),
            // sin filtrar por si las reconocemos o no. Respaldo para cuando el archivo trae
            // columnas fuera de lo mapeado — el operador ve el dato crudo sin abrir el Excel.
            'raw_columns' => $raw['_original_columns'] ?? [],

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
                    'name_fallback'    => $this->matchedRow->name_normalized,
                    'birth_date'       => $this->matchedRow->birth_date?->toDateString(),
                    'dni'              => $matchedRaw['dni'] ?? null,
                    'school_name'      => $matchedRaw['school_name'] ?? null,
                    'mother_name'      => $matchedRaw['mother_name'] ?? null,
                    'father_name'      => $matchedRaw['father_name'] ?? null,
                    'match_confidence' => $this->matchedRow->match_confidence,
                ];
            }),

            // Niño vinculado (si ya fue resuelto)
            'child' => $this->whenLoaded('child', fn () =>
                $this->child ? new ChildResource($this->child) : null
            ),

            // Sugerencia de matchChild() (DNI+nombre+apellido) cuando el vínculo
            // no es lo bastante seguro como para resolverse solo — el operador
            // decide si aceptarla o no. Distinto de 'child', que solo se completa
            // cuando la fila ya está resuelta (auto o manualmente).
            'suggested_child' => $this->whenLoaded('suggestedChild', fn () =>
                $this->suggestedChild ? [
                    'id'         => $this->suggestedChild->id,
                    'first_name' => $this->suggestedChild->first_name,
                    'last_name'  => $this->suggestedChild->last_name,
                    'birth_date' => $this->suggestedChild->birth_date?->toDateString(),
                    'birth_date_is_placeholder' => (bool) $this->suggestedChild->birth_date_is_placeholder,
                    'dni'        => $this->suggestedChild->dni,
                ] : null
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