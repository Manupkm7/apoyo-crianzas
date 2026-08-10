<?php

namespace App\Http\Requests;

use App\Models\Institution;
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
            // El admin no tiene institución propia — elige para qué institución de
            // salud carga el registro (validado en withValidator). El resto de los
            // usuarios siempre usa la suya propia, este campo se ignora.
            'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],

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

    /**
     * Institución para la que se está creando el registro.
     *
     * El admin no tiene institución propia — la elige explícitamente. El resto
     * de los usuarios siempre usa la suya.
     */
    public function targetInstitution(): ?Institution
    {
        if ($this->user()->hasRole('admin')) {
            return Institution::find($this->input('institution_id'));
        }

        return $this->user()->institution;
    }

    /**
     * Verifica que, si es admin, haya elegido una institución de salud válida.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->user()->hasRole('admin')) {
                return;
            }

            $institution = $this->targetInstitution();

            if (! $institution) {
                $validator->errors()->add('institution_id', 'Elegí para qué institución de salud es este registro.');
                return;
            }

            if ($institution->type !== 'salud') {
                $validator->errors()->add('institution_id', 'La institución elegida no es de tipo salud.');
            }
        });
    }
}
