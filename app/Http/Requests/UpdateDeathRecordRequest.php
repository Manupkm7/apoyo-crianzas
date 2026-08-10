<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateDeathRecordRequest — Valida los datos para corregir un registro de defunción.
 *
 * Actualización parcial: solo se modifican los campos enviados.
 * Solo el administrador puede usar este endpoint (DeathRecordPolicy::update()).
 */
class UpdateDeathRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['sometimes', 'uuid', 'exists:institutions,id'],

            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'death_date' => ['sometimes', 'date', 'after_or_equal:birth_date', 'before_or_equal:today'],

            'child_dni'  => ['nullable', 'string', 'max:15'],
            'mother_dni' => ['sometimes', 'string', 'max:15'],

            'cause_of_death' => ['nullable', 'string', 'max:1000'],
            'observations'   => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'death_date.after_or_equal'  => 'La fecha de defunción no puede ser anterior a la de nacimiento.',
            'death_date.before_or_equal' => 'La fecha de defunción no puede ser futura.',
        ];
    }
}
