<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreEducationRecordRequest — Valida los datos para crear un registro educativo.
 *
 * Solo pueden usar este endpoint usuarios de instituciones de tipo 'educacion'
 * (o el administrador). La verificación del tipo de institución se hace en la Policy.
 */
class StoreEducationRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La verificación del tipo de institución ocurre en EducationRecordPolicy::create()
        return $this->user()->can('ninos.gestionar') || $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'school_name'    => ['required', 'string', 'max:200'],

            // Grado o sala: ej. "1er grado", "Sala de 4", "Jardín de infantes"
            'grade_or_year'  => ['nullable', 'string', 'max:50'],

            // Cantidad de inasistencias en el ciclo lectivo actual
            'absences_count' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // ¿Está actualmente escolarizado? false = fuera de la escuela (señal de alerta)
            'is_enrolled'    => ['boolean'],

            'observations'   => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'school_name.required'   => 'El nombre de la escuela es obligatorio.',
            'absences_count.min'     => 'La cantidad de inasistencias no puede ser negativa.',
            'absences_count.integer' => 'La cantidad de inasistencias debe ser un número entero.',
        ];
    }
}
