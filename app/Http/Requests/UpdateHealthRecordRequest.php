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
            'health_center_name'      => ['sometimes', 'string', 'max:200'],
            'healthy_checkup_current' => ['sometimes', 'boolean'],
            'vaccines_current'        => ['sometimes', 'boolean'],
            'last_checkup_date'       => ['nullable', 'date', 'before_or_equal:today'],
            'observations'            => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'last_checkup_date.before_or_equal' => 'La fecha del último control no puede ser futura.',
        ];
    }
}
