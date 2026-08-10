<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreDeathRecordRequest — Valida los datos para crear manualmente un registro de defunción.
 *
 * Uso excepcional: la mayoría se generan vía importación masiva. Este endpoint
 * es para altas o correcciones puntuales que solo puede hacer el administrador
 * (verificado en DeathRecordPolicy::create()).
 */
class StoreDeathRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            // Institución que registró la defunción — obligatoria (igual que en la tabla)
            'institution_id' => ['required', 'uuid', 'exists:institutions,id'],

            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'death_date' => ['required', 'date', 'after_or_equal:birth_date', 'before_or_equal:today'],

            'child_dni' => ['nullable', 'string', 'max:15'],

            // La madre es el vínculo principal de matching familiar — siempre requerida
            'mother_dni' => ['required', 'string', 'max:15'],

            'cause_of_death' => ['nullable', 'string', 'max:1000'],
            'observations'   => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required'        => 'El nombre del niño es obligatorio.',
            'last_name.required'         => 'El apellido del niño es obligatorio.',
            'birth_date.required'        => 'La fecha de nacimiento es obligatoria.',
            'death_date.required'        => 'La fecha de defunción es obligatoria.',
            'death_date.after_or_equal'  => 'La fecha de defunción no puede ser anterior a la de nacimiento.',
            'death_date.before_or_equal' => 'La fecha de defunción no puede ser futura.',
            'mother_dni.required'        => 'El DNI de la madre es obligatorio para vincular el registro.',
            'institution_id.required'    => 'Debe indicarse la institución que registró la defunción.',
        ];
    }
}
