<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateBirthRecordRequest — Valida los datos para corregir un registro de nacimiento.
 *
 * Actualización parcial: solo se modifican los campos enviados.
 * Solo el administrador puede usar este endpoint (BirthRecordPolicy::update()).
 */
class UpdateBirthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],

            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'date', 'before_or_equal:today'],

            'mother_name' => ['nullable', 'string', 'max:200'],
            'mother_dni'  => ['nullable', 'string', 'max:15'],

            'father_name' => ['nullable', 'string', 'max:200'],
            'father_dni'  => ['nullable', 'string', 'max:15'],

            'address'             => ['nullable', 'string', 'max:300'],
            'birth_establishment' => ['nullable', 'string', 'max:200'],
            'observations'        => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
        ];
    }
}
