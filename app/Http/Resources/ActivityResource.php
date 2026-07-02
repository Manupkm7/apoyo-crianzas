<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ActivityResource — Formatea un registro de actividad para el frontend.
 *
 * Muestra qué hizo un usuario: qué acción realizó, sobre qué modelo y qué cambió.
 * Usado principalmente por los responsables de institución para ver la
 * actividad de sus representantes.
 */
class ActivityResource extends JsonResource
{
    // Mapea nombres de clases PHP a etiquetas legibles en español
    private const MODEL_LABELS = [
        'Child'           => 'Niño',
        'HealthRecord'    => 'Registro de salud',
        'EducationRecord' => 'Registro educativo',
        'Institution'     => 'Institución',
        'User'            => 'Usuario',
    ];

    // Mapea los eventos del log a verbos en español
    private const ACTION_LABELS = [
        'created' => 'Creó',
        'updated' => 'Modificó',
        'deleted' => 'Eliminó',
    ];

    public function toArray(Request $request): array
    {
        $modelName = $this->subject_type ? class_basename($this->subject_type) : null;

        return [
            'id'           => $this->id,
            'action'       => $this->description,
            'action_label' => self::ACTION_LABELS[$this->description] ?? $this->description,
            'model_type'   => $modelName,
            'model_label'  => $modelName ? (self::MODEL_LABELS[$modelName] ?? $modelName) : null,
            'subject_id'   => $this->subject_id,
            // attribute_changes guarda { "old": {...}, "attributes": {...} } en v5
            'changes'      => $this->attribute_changes,
            'created_at'   => $this->created_at->toISOString(),
        ];
    }
}
