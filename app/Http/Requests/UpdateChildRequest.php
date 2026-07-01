<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * UpdateChildRequest — Valida los datos para modificar un niño existente.
 *
 * Actualización parcial: solo se modifican los campos enviados.
 * La autorización detallada (¿puede este usuario modificar ESTE niño?) se
 * verifica en el controlador mediante la Policy.
 */
class UpdateChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('ninos.gestionar') || $this->user()->canBypassRls();
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'dni'        => ['nullable', 'string', 'max:15'],
            'notes'      => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede ser futura.',
        ];
    }
}
