<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * HealthPeriodReportRequest — Valida la carga (POST) y la corrección (PATCH)
 * de un reporte bimestral del registro de salud.
 *
 * En POST, year + bimester identifican el reporte y son obligatorios. En PATCH
 * no se aceptan; el resto de los campos es parcial.
 *
 * La verificación de que el usuario sea de la institución de salud dueña del
 * registro la hace HealthRecordPolicy en el controlador.
 */
class HealthPeriodReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ninos.gestionar') || $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $rules = [
            'healthy_checkup_current' => ['sometimes', 'nullable', 'boolean'],
            'vaccines_current'        => ['sometimes', 'nullable', 'boolean'],
            'last_checkup_date'       => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'weight_kg'               => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99'],
            'height_cm'               => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99'],
            'summary'                 => ['sometimes', 'nullable', 'string', 'max:3000'],
        ];

        if ($this->isMethod('post')) {
            $rules['year']     = ['required', 'integer', 'min:2000', 'max:2100'];
            $rules['bimester'] = ['required', 'integer', 'min:1', 'max:6'];
        } else {
            $rules['year']     = ['prohibited'];
            $rules['bimester'] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'year.required'       => 'Indicá el año.',
            'bimester.required'   => 'Indicá el bimestre.',
            'bimester.min'        => 'El bimestre debe estar entre 1 y 6.',
            'bimester.max'        => 'El bimestre debe estar entre 1 y 6.',
            'year.prohibited'     => 'No se puede cambiar el año de un reporte ya cargado.',
            'bimester.prohibited' => 'No se puede cambiar el bimestre de un reporte ya cargado.',
            'last_checkup_date.before_or_equal' => 'La fecha del último control no puede ser futura.',
        ];
    }
}
