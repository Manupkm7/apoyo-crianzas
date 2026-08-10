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

            'level'          => ['sometimes', 'nullable', 'in:jardin,primario,secundario'],
            'grade'          => ['nullable', 'integer', 'min:1', 'max:7', 'required_with:level'],

            'absences_count' => ['sometimes', 'integer', 'min:0', 'max:9999'],

            'attendance_present_days'  => ['nullable', 'integer', 'min:0', 'max:999'],
            'attendance_total_days'    => ['nullable', 'integer', 'min:0', 'max:999', 'gte:attendance_present_days'],
            'attendance_period_label'  => ['nullable', 'string', 'max:100'],

            'is_enrolled'    => ['sometimes', 'boolean'],
            'observations'   => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'absences_count.min'        => 'La cantidad de inasistencias no puede ser negativa.',
            'absences_count.integer'    => 'La cantidad de inasistencias debe ser un número entero.',
            'grade.required_with'       => 'Indicá el grado/sala correspondiente al nivel elegido.',
            'attendance_total_days.gte' => 'Los días totales no pueden ser menores a los días asistidos.',
        ];
    }

    /**
     * Verifica que el nivel/grado elegidos existan en la configuración de la institución
     * dueña del registro (no la del usuario, por si en el futuro un admin edita en nombre
     * de otra institución — hoy coinciden siempre).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->has('level') || ! $this->input('level')) {
                return;
            }

            $level       = $this->input('level');
            $institution = $this->route('child')?->educationRecord?->institution ?? $this->user()->institution;
            $maxGrade    = $institution?->maxGradeForLevel($level);

            if ($maxGrade === null) {
                $validator->errors()->add('level', 'Esta institución no ofrece este nivel educativo.');
                return;
            }

            $grade = (int) $this->input('grade');
            if ($grade > $maxGrade) {
                $validator->errors()->add('grade', "El grado máximo para este nivel es {$maxGrade}.");
            }
        });
    }
}
