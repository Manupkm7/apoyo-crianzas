<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreBirthRecordRequest — Valida los datos para crear manualmente un registro de nacimiento.
 *
 * Uso excepcional: la mayoría de los registros de nacimiento se generan vía
 * importación masiva. Este endpoint es para altas o correcciones puntuales
 * que solo puede hacer el administrador (verificado en BirthRecordPolicy::create()).
 */
class StoreBirthRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'institution_id' => ['nullable', 'uuid', 'exists:institutions,id'],

            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],

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
            'first_name.required' => 'El nombre del niño es obligatorio.',
            'last_name.required'  => 'El apellido del niño es obligatorio.',
            'birth_date.required' => 'La fecha de nacimiento es obligatoria.',
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
        ];
    }
}
