<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * EducationPeriodReportRequest — Valida la carga (POST) y la corrección (PATCH)
 * de un reporte bimestral del registro educativo.
 *
 * En POST, year + bimester identifican el reporte y son obligatorios. En PATCH
 * no se aceptan (para cambiar de bimestre se borra y se crea de nuevo); el
 * resto de los campos es parcial.
 *
 * La verificación de que el usuario sea de la institución educativa dueña del
 * registro la hace EducationRecordPolicy en el controlador.
 */
class EducationPeriodReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ninos.gestionar') || $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $rules = [
            'level'          => ['sometimes', 'nullable', 'in:jardin,primario,secundario'],
            'grade'          => ['sometimes', 'nullable', 'integer', 'min:1', 'max:7', 'required_with:level'],
            'is_enrolled'    => ['sometimes', 'boolean'],
            'absences_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'present_days'   => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999'],
            'total_days'     => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999', 'gte:present_days'],
            'summary'        => ['sometimes', 'nullable', 'string', 'max:3000'],
        ];

        if ($this->isMethod('post')) {
            $rules['year']     = ['required', 'integer', 'min:2000', 'max:2100'];
            $rules['bimester'] = ['required', 'integer', 'min:1', 'max:6'];
        } else {
            // No se puede mover un reporte de bimestre — solo corregir su contenido.
            $rules['year']     = ['prohibited'];
            $rules['bimester'] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'year.required'      => 'Indicá el año del ciclo lectivo.',
            'bimester.required'  => 'Indicá el bimestre.',
            'bimester.min'       => 'El bimestre debe estar entre 1 y 6.',
            'bimester.max'       => 'El bimestre debe estar entre 1 y 6.',
            'year.prohibited'    => 'No se puede cambiar el año de un reporte ya cargado.',
            'bimester.prohibited' => 'No se puede cambiar el bimestre de un reporte ya cargado.',
            'grade.required_with' => 'Indicá el grado/sala correspondiente al nivel elegido.',
            'total_days.gte'     => 'Los días totales no pueden ser menores a los días asistidos.',
        ];
    }
}
