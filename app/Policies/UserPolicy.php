<?php

namespace App\Policies;

use App\Models\User;

/**
 * UserPolicy — Define quién puede ver, crear, modificar o desactivar usuarios.
 *
 * Hay tres niveles de gestión de usuarios:
 *
 * 1. Administrador — gestiona TODOS los usuarios del sistema (cualquier rol, cualquier institución).
 * 2. Responsable de institución (`institucion`) — gestiona SOLO los representantes
 *    de SU propia institución. No puede gestionar admins, coordinadores ni responsables de otras instituciones.
 * 3. Usuario regular — solo puede ver su propio perfil (sin modificar su rol ni institución).
 *
 * Una restricción importante: nadie puede modificar su propio rol ni desactivarse a sí mismo.
 */
class UserPolicy
{
    /**
     * ¿Puede este usuario ver el listado de usuarios?
     *
     * - Admin: ve todos los usuarios del sistema.
     * - Responsable de institución: ve solo sus representantes (el controlador filtra).
     * - Representante y demás: no tienen acceso al listado.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('usuarios.gestionar') || $user->can('representantes.gestionar');
    }

    /**
     * ¿Puede este usuario ver el perfil de otro usuario?
     *
     * - Cualquier usuario puede verse a sí mismo.
     * - Admin puede ver a cualquier usuario.
     * - Responsable de institución puede ver a sus representantes.
     */
    public function view(User $authUser, User $targetUser): bool
    {
        // Todo usuario puede ver su propio perfil
        if ($authUser->id === $targetUser->id) {
            return true;
        }

        // Admin ve a cualquier usuario
        if ($authUser->can('usuarios.gestionar')) {
            return true;
        }

        // El responsable puede ver a los representantes de su propia institución
        if ($authUser->can('representantes.gestionar')) {
            return $targetUser->institution_id === $authUser->institution_id
                && $targetUser->hasRole('representante');
        }

        return false;
    }

    /**
     * ¿Puede este usuario crear un nuevo usuario?
     *
     * - Admin puede crear usuarios con cualquier rol.
     * - Responsable de institución puede crear solo representantes para su institución.
     *   (La validación del rol y la institución se hace en StoreUserRequest.)
     */
    public function create(User $user): bool
    {
        return $user->can('usuarios.gestionar') || $user->can('representantes.gestionar');
    }

    /**
     * ¿Puede este usuario modificar los datos de otro usuario?
     *
     * - Cualquier usuario puede modificar su propio perfil (nombre, email, contraseña).
     *   No puede cambiar su propio rol, institución, ni desactivarse.
     * - Admin puede modificar cualquier usuario.
     * - Responsable puede modificar datos de sus representantes (sin cambiarles el rol).
     */
    public function update(User $authUser, User $targetUser): bool
    {
        // Cada usuario puede editar su propio perfil
        if ($authUser->id === $targetUser->id) {
            return true;
        }

        // Admin modifica cualquier usuario
        if ($authUser->can('usuarios.gestionar')) {
            return true;
        }

        // El responsable modifica solo sus representantes
        if ($authUser->can('representantes.gestionar')) {
            return $targetUser->institution_id === $authUser->institution_id
                && $targetUser->hasRole('representante');
        }

        return false;
    }

    /**
     * ¿Puede este usuario desactivar (dar de baja) a otro usuario?
     *
     * Nadie puede desactivarse a sí mismo.
     * - Admin puede desactivar cualquier usuario.
     * - Responsable puede desactivar sus representantes.
     *
     * Desactivar no elimina el usuario del sistema; solo impide que pueda iniciar sesión.
     */
    public function delete(User $authUser, User $targetUser): bool
    {
        // Nadie puede desactivarse a sí mismo
        if ($authUser->id === $targetUser->id) {
            return false;
        }

        // Admin puede desactivar a cualquier usuario
        if ($authUser->can('usuarios.gestionar')) {
            return true;
        }

        // El responsable puede desactivar a sus representantes
        if ($authUser->can('representantes.gestionar')) {
            return $targetUser->institution_id === $authUser->institution_id
                && $targetUser->hasRole('representante');
        }

        return false;
    }
}
