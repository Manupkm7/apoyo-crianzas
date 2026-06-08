<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateInstitutionRequest — Valida los datos para modificar una institución existente.
 *
 * Diferencias con StoreInstitutionRequest:
 * - Todos los campos son opcionales (es un PATCH parcial).
 * - La validación de unicidad del nombre ignora la institución actual
 *   (así se permite guardar sin cambiar el nombre).
 */
class UpdateInstitutionRequest extends FormRequest
{
    /**
     * Solo el administrador puede modificar instituciones.
     */
    public function authorize(): bool
    {
        return $this->user()->can('instituciones.gestionar');
    }

    /**
     * Define qué campos se pueden actualizar y cómo deben ser.
     *
     * El uso de 'sometimes' permite enviar solo los campos que se quieren cambiar.
     */
    public function rules(): array
    {
        // Obtenemos el ID de la institución que se está editando para excluirla
        // de la validación de nombre único (así puede guardar con el mismo nombre)
        $institutionId = $this->route('institution')?->id;

        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                // El nombre debe ser único, pero ignoramos la institución actual
                Rule::unique('institutions', 'name')
                    ->ignore($institutionId)
                    ->whereNull('deleted_at'),
            ],

            'type' => [
                'sometimes',
                Rule::in(['salud', 'educacion', 'desarrollo_social', 'justicia', 'otro']),
            ],

            'address'   => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone'     => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Mensajes de error en español para el frontend.
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe otra institución con ese nombre.',
            'type.in'     => 'El tipo debe ser: salud, educacion, desarrollo_social, justicia u otro.',
        ];
    }
}
