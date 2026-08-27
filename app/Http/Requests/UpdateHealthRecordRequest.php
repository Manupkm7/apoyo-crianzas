<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateHealthRecordRequest — Valida los datos para modificar un registro de salud.
 *
 * Actualización parcial: solo se modifican los campos enviados.
 * La autorización detallada se verifica en el controlador mediante la Policy.
 */
class UpdateHealthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ninos.gestionar') || $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            // Reasignación de institución: SOLO el admin puede mover un registro
            // de salud a otra institución. Tipo/estado se validan en withValidator().
            'institution_id' => $this->user()->hasRole('admin')
                ? ['sometimes', 'uuid', 'exists:institutions,id']
                : ['prohibited'],

            'health_center_name'      => ['sometimes', 'string', 'max:200'],
            'healthy_checkup_current' => ['sometimes', 'boolean'],
            'vaccines_current'        => ['sometimes', 'boolean'],
            'last_checkup_date'       => ['nullable', 'date', 'before_or_equal:today'],
            'observations'            => ['nullable', 'string', 'max:3000'],
            'health_profile'          => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'last_checkup_date.before_or_equal' => 'La fecha del último control no puede ser futura.',
            'institution_id.prohibited'         => 'Solo el administrador puede cambiar la institución de un registro.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('institution_id')) {
                return;
            }

            $institution = \App\Models\Institution::find($this->input('institution_id'));

            if (! $institution || ! $institution->is_active) {
                $validator->errors()->add('institution_id', 'La institución elegida no existe o está desactivada.');
                return;
            }

            if ($institution->type !== 'salud') {
                $validator->errors()->add('institution_id', 'La institución elegida no es de tipo salud.');
            }
        });
    }
}
