<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * UpdateInstitutionRequest — Valida los datos para modificar una institución.
 *
 * Dos niveles de acceso:
 *
 * 1. Administrador: puede modificar todos los campos (nombre, tipo, dirección,
 *    teléfono y estado activo/inactivo).
 *
 * 2. Responsable de institución (rol 'institucion'): solo puede modificar
 *    los datos de contacto (dirección y teléfono) de SU propia institución.
 *    Nombre, tipo y estado son campos sensibles que solo el admin controla.
 */
class UpdateInstitutionRequest extends FormRequest
{
    /**
     * ¿Está autorizado este usuario a modificar esta institución?
     *
     * - Admin: puede modificar cualquier institución.
     * - Responsable: solo puede modificar la suya propia.
     */
    public function authorize(): bool
    {
        $institution = $this->route('institution');
        $user        = $this->user();

        if ($user->can('instituciones.gestionar')) {
            return true;
        }

        // El responsable puede editar datos de contacto de su propia institución
        return $user->isInstitucion() && $user->institution_id === $institution?->id;
    }

    /**
     * Define qué campos se pueden actualizar según el rol del usuario.
     *
     * El uso de 'sometimes' permite enviar solo los campos que se quieren cambiar (PATCH).
     */
    public function rules(): array
    {
        $institutionId = $this->route('institution')?->id;

        // Datos de contacto: cualquier usuario autorizado puede actualizarlos
        $contactRules = [
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone'   => ['sometimes', 'nullable', 'string', 'max:30'],
        ];

        // Campos sensibles: solo el admin puede modificarlos
        if (! $this->user()->can('instituciones.gestionar')) {
            return $contactRules;
        }

        return array_merge($contactRules, [
            'name' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('institutions', 'name')
                    ->ignore($institutionId)
                    ->whereNull('deleted_at'),
            ],
            'type'      => ['sometimes', Rule::in(['salud', 'educacion', 'desarrollo_social', 'justicia', 'otro'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
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
