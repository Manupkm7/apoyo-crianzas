<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * StoreInstitutionRequest — Valida los datos para crear una nueva institución.
 *
 * Esta clase se ejecuta ANTES de que el controlador procese la solicitud.
 * Si algún dato no cumple las reglas, el sistema devuelve un error 422
 * con el detalle de qué campos fallaron, sin llegar al controlador.
 *
 * Solo los administradores pueden crear instituciones (verificado en authorize).
 */
class StoreInstitutionRequest extends FormRequest
{
    /**
     * ¿Está autorizado este usuario a crear una institución?
     *
     * La autorización detallada (por Policy) se verifica en el controlador.
     * Aquí hacemos una verificación rápida del permiso.
     */
    public function authorize(): bool
    {
        return $this->user()->can('instituciones.gestionar');
    }

    /**
     * Define qué campos son obligatorios y cómo deben ser.
     */
    public function rules(): array
    {
        return [
            // Nombre de la institución — obligatorio y único en el sistema
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('institutions', 'name')->whereNull('deleted_at'),
            ],

            // Tipo de institución — debe ser uno de los valores predefinidos
            'type' => [
                'required',
                Rule::in(['salud', 'educacion', 'desarrollo_social', 'justicia', 'otro']),
            ],

            // Localidad — obligatoria para instituciones nuevas (catálogo geográfico
            // del login institucional). Las instituciones creadas antes de este catálogo
            // quedan con locality_id null hasta completarse a mano.
            'locality_id' => ['required', 'uuid', 'exists:localities,id'],

            // Domicilio — opcional
            'address' => ['nullable', 'string', 'max:500'],

            // Teléfono — opcional
            'phone' => ['nullable', 'string', 'max:30'],

            // Si está activa al crearla (por defecto sí)
            'is_active' => ['boolean'],

            // Niveles educativos que ofrece — solo tiene efecto si type === 'educacion'
            'offers_jardin'     => ['boolean'],
            'offers_primario'   => ['boolean'],
            'primario_years'    => ['nullable', 'integer', Rule::in([6, 7]), 'required_if:offers_primario,true'],
            'offers_secundario' => ['boolean'],
            'secundario_years'  => ['nullable', 'integer', Rule::in([6, 7]), 'required_if:offers_secundario,true'],
        ];
    }

    /**
     * Mensajes de error en español para el frontend.
     */
    public function messages(): array
    {
        return [
            'name.required'  => 'El nombre de la institución es obligatorio.',
            'name.unique'    => 'Ya existe una institución con ese nombre.',
            'type.required'  => 'El tipo de institución es obligatorio.',
            'locality_id.required' => 'La localidad de la institución es obligatoria.',
            'locality_id.exists'   => 'La localidad seleccionada no existe.',
            'type.in'        => 'El tipo debe ser: salud, educacion, desarrollo_social, justicia u otro.',
            'primario_years.required_if'   => 'Indicá la cantidad de años de primario (6 o 7).',
            'secundario_years.required_if' => 'Indicá la cantidad de años de secundario (6 o 7).',
        ];
    }
}
