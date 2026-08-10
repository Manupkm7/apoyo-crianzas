<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResolveImportRowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('importaciones.gestionar');
    }

    public function rules(): array
    {
        return [
            // confirm → el operador vincula/crea el registro
            // skip    → el operador descarta la fila (no genera ningún registro)
            'action' => ['required', 'in:confirm,skip'],

            // UUID de un niño existente en el sistema.
            // Si se provee, la fila se vincula a ese niño.
            // Si no se provee y action=confirm, se crea un nuevo niño con los datos de la fila.
            'child_id' => ['nullable', 'uuid', 'exists:children,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => 'Debe indicar si confirmar o descartar el registro.',
            'action.in'       => 'La acción debe ser "confirm" o "skip".',
            'child_id.exists' => 'El niño seleccionado no existe en el sistema.',
        ];
    }
}