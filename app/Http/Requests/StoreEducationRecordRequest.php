<?php

namespace App\Http\Requests;

use App\Models\Institution;
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
            // El admin no tiene institución propia — elige para qué institución
            // educativa carga el registro (validado en withValidator). El resto
            // de los usuarios siempre usa la suya propia, este campo se ignora.
            'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],

            'school_name'    => ['required', 'string', 'max:200'],

            // Grado o sala en texto libre (histórico) — se conserva pero ya no es la fuente
            // principal: usar 'level' + 'grade' para poder agrupar por grado en el dashboard.
            'grade_or_year'  => ['nullable', 'string', 'max:50'],

            'level'          => ['nullable', 'in:jardin,primario,secundario'],
            'grade'          => ['nullable', 'integer', 'min:1', 'max:7', 'required_with:level'],

            // Cantidad de inasistencias en el ciclo lectivo actual
            'absences_count' => ['nullable', 'integer', 'min:0', 'max:9999'],

            // Asistencia del período actual (ej. "34 de 40 días este bimestre")
            'attendance_present_days'  => ['nullable', 'integer', 'min:0', 'max:999'],
            'attendance_total_days'    => ['nullable', 'integer', 'min:0', 'max:999', 'gte:attendance_present_days'],
            'attendance_period_label'  => ['nullable', 'string', 'max:100'],

            // ¿Está actualmente escolarizado? false = fuera de la escuela (señal de alerta)
            'is_enrolled'    => ['boolean'],

            'observations'   => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'school_name.required'         => 'El nombre de la escuela es obligatorio.',
            'absences_count.min'           => 'La cantidad de inasistencias no puede ser negativa.',
            'absences_count.integer'       => 'La cantidad de inasistencias debe ser un número entero.',
            'grade.required_with'          => 'Indicá el grado/sala correspondiente al nivel elegido.',
            'attendance_total_days.gte'    => 'Los días totales no pueden ser menores a los días asistidos.',
        ];
    }

    /**
     * Institución para la que se está creando el registro.
     *
     * El admin no tiene institución propia (institution_id es null en su usuario),
     * así que la elige explícitamente en el formulario. El resto de los usuarios
     * siempre usa la suya — no pueden cargar registros para otra institución.
     */
    public function targetInstitution(): ?Institution
    {
        if ($this->user()->hasRole('admin')) {
            return Institution::find($this->input('institution_id'));
        }

        return $this->user()->institution;
    }

    /**
     * Verifica que se haya elegido una institución educativa válida (caso admin)
     * y que el nivel/grado elegidos existan en su configuración.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $institution = $this->targetInstitution();

            if ($this->user()->hasRole('admin')) {
                if (! $institution) {
                    $validator->errors()->add('institution_id', 'Elegí para qué institución educativa es este registro.');
                    return;
                }
                if ($institution->type !== 'educacion') {
                    $validator->errors()->add('institution_id', 'La institución elegida no es de tipo educación.');
                    return;
                }
            }

            $level = $this->input('level');
            if (! $level) {
                return;
            }

            $maxGrade = $institution?->maxGradeForLevel($level);

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
