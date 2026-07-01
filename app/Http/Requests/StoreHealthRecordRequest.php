<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreHealthRecordRequest — Valida los datos para crear un registro de salud.
 *
 * Solo pueden usar este endpoint usuarios de instituciones de tipo 'salud'
 * (o el administrador). La verificación del tipo de institución se hace en la Policy.
 */
class StoreHealthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La verificación del tipo de institución ocurre en HealthRecordPolicy::create()
        return $this->user()->can('ninos.gestionar') || $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            // Centro de salud: salita barrial, CAPS, hospital, etc.
            'health_center_name'      => ['required', 'string', 'max:200'],

            // Control de niño sano: false = sin controles al día (señal de alerta)
            'healthy_checkup_current' => ['required', 'boolean'],

            // Vacunas: false = esquema incompleto (señal de alerta)
            'vaccines_current'        => ['required', 'boolean'],

            // Fecha del último control (para detectar ausencia prolongada en el SAT)
            'last_checkup_date'       => ['nullable', 'date', 'before_or_equal:today'],

            'observations'            => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'health_center_name.required'      => 'El nombre del centro de salud es obligatorio.',
            'healthy_checkup_current.required' => 'Indicar si tiene el control de niño sano al día es obligatorio.',
            'vaccines_current.required'        => 'Indicar si las vacunas están al día es obligatorio.',
            'last_checkup_date.before_or_equal'=> 'La fecha del último control no puede ser futura.',
        ];
    }
}
