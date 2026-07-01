<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StoreChildRequest — Valida los datos para registrar un nuevo niño.
 *
 * La unicidad del DNI no se verifica aquí contra la columna dni (que está cifrada
 * y no permite comparación directa), sino contra dni_hash en el controlador.
 * Si ya existe un niño con ese DNI, el controlador devuelve un error antes de crear.
 */
class StoreChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ninos.gestionar') || $this->user()->can('reportes.ver');
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],

            // La fecha de nacimiento no puede ser futura (no se puede registrar a alguien no nacido)
            'birth_date' => ['required', 'date', 'before_or_equal:today'],

            // DNI opcional (puede que no tenga aún al momento del registro)
            'dni'        => ['nullable', 'string', 'max:15'],

            'notes'      => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required'       => 'El nombre del niño es obligatorio.',
            'last_name.required'        => 'El apellido del niño es obligatorio.',
            'birth_date.required'       => 'La fecha de nacimiento es obligatoria.',
            'birth_date.before_or_equal'=> 'La fecha de nacimiento no puede ser futura.',
        ];
    }
}
