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

            // Cuando hay una contraparte (matched_row) y no se provee child_id, con cuál
            // de las dos filas se identifica al niño nuevo (nombre + fecha de nacimiento) —
            // 'row' (default) = esta fila, 'matched_row' = la contraparte mostrada al lado.
            'data_source' => ['nullable', 'in:row,matched_row'],

            // Correcciones que el operador tipea a mano antes de confirmar (pedido del
            // cliente: poder editar el niño al aceptar el matcheo, ej. corregir un DNI o
            // nombre mal cargado en el archivo original). Solo pisan los datos de ESTA
            // fila — nunca los de la contraparte — ver ImportController::confirmRow().
            'overrides'                          => ['nullable', 'array'],
            'overrides.first_name'               => ['nullable', 'string', 'max:255'],
            'overrides.last_name'                => ['nullable', 'string', 'max:255'],
            'overrides.dni'                       => ['nullable', 'string', 'max:20'],
            'overrides.birth_date'                => ['nullable', 'date'],
            'overrides.mother_name'               => ['nullable', 'string', 'max:255'],
            'overrides.mother_dni'                => ['nullable', 'string', 'max:20'],
            'overrides.father_name'               => ['nullable', 'string', 'max:255'],
            'overrides.father_dni'                => ['nullable', 'string', 'max:20'],
            'overrides.address'                   => ['nullable', 'string', 'max:255'],
            'overrides.birth_establishment'       => ['nullable', 'string', 'max:255'],
            'overrides.school_name'               => ['nullable', 'string', 'max:255'],
            'overrides.grade_or_year'             => ['nullable', 'string', 'max:100'],
            'overrides.health_center_name'        => ['nullable', 'string', 'max:255'],
            'overrides.healthy_checkup_current'   => ['nullable', 'boolean'],
            'overrides.vaccines_current'          => ['nullable', 'boolean'],
            'overrides.last_checkup_date'         => ['nullable', 'date'],
            'overrides.observations'              => ['nullable', 'string', 'max:1000'],
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