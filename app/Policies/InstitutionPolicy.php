<?php

namespace App\Policies;

use App\Models\Institution;
use App\Models\User;

/**
 * InstitutionPolicy — Define quién puede hacer qué con las instituciones.
 *
 * Reglas generales:
 * - Solo el administrador puede crear, modificar o desactivar instituciones.
 * - El coordinador puede ver todas las instituciones (necesita ese contexto para su trabajo).
 * - Un usuario de institución (responsable o representante) solo puede ver SU propia institución.
 *
 * En la práctica, cuando el frontend pide la lista de instituciones:
 * - Un admin ve todas.
 * - Un coordinador ve todas.
 * - Un responsable de institución ve solo la suya.
 */
class InstitutionPolicy
{
    /**
     * ¿Puede este usuario ver el listado de instituciones?
     *
     * Admins y coordinadores ven todas. Los usuarios institucionales
     * también tienen acceso (el controlador filtra el resultado a la suya).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('instituciones.gestionar')
            || $user->can('reportes.ver')
            || $user->isInstitutionalUser();
    }

    /**
     * ¿Puede este usuario ver el detalle de una institución específica?
     *
     * - Admin y coordinador: pueden ver cualquier institución.
     * - Responsable/representante: solo pueden ver la institución a la que pertenecen.
     */
    public function view(User $user, Institution $institution): bool
    {
        // Admin y coordinador ven cualquier institución
        if ($user->can('instituciones.gestionar') || $user->can('reportes.ver')) {
            return true;
        }

        // Los usuarios institucionales solo ven la propia
        return $user->institution_id === $institution->id;
    }

    /**
     * ¿Puede este usuario crear una nueva institución?
     *
     * Solo el administrador puede dar de alta instituciones nuevas.
     */
    public function create(User $user): bool
    {
        return $user->can('instituciones.gestionar');
    }

    /**
     * ¿Puede este usuario modificar los datos de una institución?
     *
     * Solo el administrador puede modificar instituciones existentes.
     */
    public function update(User $user, Institution $institution): bool
    {
        return $user->can('instituciones.gestionar');
    }

    /**
     * ¿Puede este usuario desactivar (dar de baja) una institución?
     *
     * Solo el administrador puede desactivar instituciones.
     * Nota: las instituciones nunca se borran físicamente del sistema,
     * solo se marcan como inactivas (soft delete).
     */
    public function delete(User $user, Institution $institution): bool
    {
        return $user->can('instituciones.gestionar');
    }
}
