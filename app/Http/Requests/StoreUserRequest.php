<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * StoreUserRequest — Valida los datos para crear un nuevo usuario.
 *
 * La lógica de roles determina qué puede hacer cada tipo de creador:
 *
 * - Administrador: puede crear usuarios con CUALQUIER rol y en CUALQUIER institución.
 * - Responsable de institución: solo puede crear representantes en SU propia institución.
 *
 * Esta validación se hace aquí antes de llegar al controlador, para dar
 * mensajes de error claros al frontend antes de tocar la base de datos.
 */
class StoreUserRequest extends FormRequest
{
    /**
     * Verificación rápida: solo admin y responsables de institución pueden crear usuarios.
     * La verificación fina por modelo se hace en UserPolicy.
     */
    public function authorize(): bool
    {
        return $this->user()->can('usuarios.gestionar')
            || $this->user()->can('representantes.gestionar');
    }

    /**
     * Define las reglas de validación para cada campo del nuevo usuario.
     */
    public function rules(): array
    {
        $authUser = $this->user();

        // Determina qué roles puede asignar el usuario que está creando
        // Admin puede asignar cualquier rol. El responsable solo puede crear representantes.
        $allowedRoles = $authUser->can('usuarios.gestionar')
            ? ['admin', 'coordinador', 'institucion', 'representante']
            : ['representante'];

        return [
            // Nombre completo — obligatorio
            'name' => ['required', 'string', 'max:255'],

            // Email — único en el sistema, obligatorio
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],

            // Contraseña — debe ser fuerte: mínimo 12 caracteres, mayúsculas,
            // minúsculas, números y símbolos. El sistema maneja datos de menores.
            'password' => [
                'required',
                'string',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],

            // Rol a asignar — restringido según quién crea el usuario
            'role' => ['required', 'string', Rule::in($allowedRoles)],

            // Institución a la que pertenecerá el usuario
            // Para admin/coordinador puede ser null; para inst/representante es obligatorio
            'institution_id' => [
                $this->isRoleRequiringInstitution() ? 'required' : 'nullable',
                'uuid',
                // La institución debe existir y estar activa
                Rule::exists('institutions', 'id')->where('is_active', true)->whereNull('deleted_at'),
                // El responsable solo puede asignar su propia institución
                function ($attribute, $value, $fail) use ($authUser) {
                    if ($authUser->can('representantes.gestionar') && ! $authUser->can('usuarios.gestionar')) {
                        if ($value !== $authUser->institution_id) {
                            $fail('Solo puede crear usuarios en su propia institución.');
                        }
                    }
                },
            ],

            // Si el usuario empieza activo (por defecto sí)
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Determina si el rol que se está asignando requiere que el usuario
     * pertenezca a una institución específica.
     *
     * Admin y coordinador son globales (sin institución fija).
     * Institución y representante necesitan institución.
     */
    private function isRoleRequiringInstitution(): bool
    {
        return in_array($this->input('role'), ['institucion', 'representante']);
    }

    /**
     * Mensajes de error en español para el frontend.
     */
    public function messages(): array
    {
        return [
            'name.required'          => 'El nombre es obligatorio.',
            'email.required'         => 'El correo electrónico es obligatorio.',
            'email.unique'           => 'Ya existe un usuario con ese correo electrónico.',
            'password.required'      => 'La contraseña es obligatoria.',
            'role.required'          => 'El rol es obligatorio.',
            'role.in'                => 'El rol seleccionado no es válido o no tiene permiso para asignarlo.',
            'institution_id.required'=> 'La institución es obligatoria para este tipo de usuario.',
            'institution_id.exists'  => 'La institución seleccionada no existe o está desactivada.',
        ];
    }
}
