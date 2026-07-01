<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * UpdateUserRequest — Valida los datos para modificar un usuario existente.
 *
 * Es un PATCH parcial: se pueden enviar solo los campos que se quieren cambiar.
 *
 * Restricciones importantes:
 * - Un usuario NO puede cambiar su propio rol ni su propia institución.
 * - Un responsable de institución NO puede cambiar el rol de sus representantes.
 * - Solo el admin puede cambiar roles e instituciones.
 * - Si se cambia a rol 'institucion', hay verificación adicional en el controlador.
 */
class UpdateUserRequest extends FormRequest
{
    /**
     * La autorización de quién puede editar a quién se verifica en UserPolicy.
     * Aquí solo comprobamos que el usuario esté autenticado.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Define qué campos se pueden actualizar y sus restricciones.
     */
    public function rules(): array
    {
        $authUser = $this->user();
        $targetUser = $this->route('user'); // El usuario que se está editando

        // El email debe ser único, ignorando el del usuario que estamos editando
        $uniqueEmail = Rule::unique('users', 'email')
            ->ignore($targetUser?->id)
            ->whereNull('deleted_at');

        $rules = [
            // Campos que cualquier usuario puede cambiar en su propio perfil
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => ['sometimes', 'email', 'max:255', $uniqueEmail],
            'password' => [
                'sometimes',
                'string',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
        ];

        // Solo el administrador puede cambiar el rol y la institución de un usuario.
        // El responsable no puede reasignar representantes a otras instituciones.
        if ($authUser->can('usuarios.gestionar')) {
            $rules['role'] = [
                'sometimes',
                'string',
                Rule::in(['admin', 'coordinador', 'institucion', 'representante']),
            ];

            $rules['institution_id'] = [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('institutions', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ];
        }

        // Solo admin y responsables pueden cambiar el estado activo/inactivo de otros usuarios.
        // Un usuario no puede desactivarse a sí mismo (eso lo controla UserPolicy::delete).
        if ($authUser->can('usuarios.gestionar') || $authUser->can('representantes.gestionar')) {
            $rules['is_active'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    /**
     * Mensajes de error en español para el frontend.
     */
    public function messages(): array
    {
        return [
            'email.unique'          => 'Ya existe otro usuario con ese correo electrónico.',
            'role.in'               => 'El rol seleccionado no es válido.',
            'institution_id.exists' => 'La institución seleccionada no existe o está desactivada.',
        ];
    }
}
