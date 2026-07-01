<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateEducationRecordRequest — Valida los datos para modificar un registro educativo.
 *
 * Actualización parcial: solo se modifican los campos enviados.
 * La autorización detallada se verifica en el controlador mediante la Policy.
 */
class UpdateEducationRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ninos.gestionar') || $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'school_name'    => ['sometimes', 'string', 'max:200'],
            'grade_or_year'  => ['nullable', 'string', 'max:50'],
            'absences_count' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_enrolled'    => ['sometimes', 'boolean'],
            'observations'   => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'absences_count.min'     => 'La cantidad de inasistencias no puede ser negativa.',
            'absences_count.integer' => 'La cantidad de inasistencias debe ser un número entero.',
        ];
    }
}
