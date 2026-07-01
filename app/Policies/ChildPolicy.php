<?php

namespace App\Policies;

use App\Models\Child;
use App\Models\User;

/**
 * ChildPolicy — Define quién puede hacer qué con los registros de niños.
 *
 * Un niño es un registro compartido entre dominios: una institución de salud
 * y una de educación pueden tener registros del mismo niño.
 *
 * Reglas de acceso:
 * - Admin y coordinador ven y gestionan todos los niños.
 * - Institución / representante: solo ven los niños vinculados a su institución
 *   (que tengan un registro de su dominio dentro de esa institución).
 */
class ChildPolicy
{
    /**
     * ¿Puede este usuario ver el listado de niños?
     * Todos los roles con acceso al módulo pueden listar (el controlador filtra por institución).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('ninos.gestionar') || $user->can('reportes.ver');
    }

    /**
     * ¿Puede este usuario ver el perfil de un niño específico?
     *
     * - Admin/coordinador: sí, siempre.
     * - Institución/representante: solo si el niño tiene un registro en su institución.
     */
    public function view(User $user, Child $child): bool
    {
        if ($user->canBypassRls()) {
            return true;
        }

        // El usuario solo puede ver niños que tengan un registro en su institución
        return $child->educationRecord?->institution_id === $user->institution_id
            || $child->healthRecord?->institution_id === $user->institution_id;
    }

    /**
     * ¿Puede este usuario registrar un nuevo niño en el sistema?
     *
     * Admin, coordinador y usuarios institucionales pueden crear niños.
     */
    public function create(User $user): bool
    {
        return $user->can('ninos.gestionar') || $user->can('reportes.ver');
    }

    /**
     * ¿Puede este usuario modificar los datos base de un niño?
     *
     * Admin/coordinador: sí. Institucionales: solo si el niño está en su institución.
     */
    public function update(User $user, Child $child): bool
    {
        if ($user->canBypassRls()) {
            return true;
        }

        return $user->can('ninos.gestionar') && (
            $child->educationRecord?->institution_id === $user->institution_id
            || $child->healthRecord?->institution_id === $user->institution_id
        );
    }

    /**
     * ¿Puede este usuario dar de baja (soft delete) un registro de niño?
     *
     * Solo el administrador puede hacer esto. Los datos nunca se eliminan físicamente.
     */
    public function delete(User $user, Child $child): bool
    {
        return $user->hasRole('admin');
    }
}
