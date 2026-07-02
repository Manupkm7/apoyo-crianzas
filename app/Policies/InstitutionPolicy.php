<?php

namespace App\Policies;

use App\Models\Institution;
use App\Models\User;

/**
 * InstitutionPolicy — Define quién puede hacer qué con las instituciones.
 *
 * Reglas generales:
 * - Admin: puede crear, modificar completamente (nombre, tipo, estado) y desactivar.
 * - Coordinador: puede ver todas las instituciones (necesita ese contexto para su trabajo).
 * - Responsable de institución: puede ver su propia institución y editar solo
 *   sus datos de contacto (dirección y teléfono) — no puede cambiar nombre, tipo ni estado.
 * - Representante: no puede gestionar instituciones en absoluto.
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
        if ($user->can('instituciones.gestionar') || $user->can('reportes.ver')) {
            return true;
        }

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
     * - Admin: puede modificar todo (nombre, tipo, dirección, teléfono, estado).
     * - Responsable de institución: puede modificar solo datos de contacto
     *   de SU propia institución (dirección y teléfono). Los campos
     *   sensibles (nombre, tipo, is_active) están restringidos en el Form Request.
     */
    public function update(User $user, Institution $institution): bool
    {
        if ($user->can('instituciones.gestionar')) {
            return true;
        }

        // El responsable puede editar los datos de contacto de su propia institución
        return $user->isInstitucion() && $user->institution_id === $institution->id;
    }

    /**
     * ¿Puede este usuario desactivar (dar de baja) una institución?
     *
     * Solo el administrador puede desactivar instituciones.
     */
    public function delete(User $user, Institution $institution): bool
    {
        return $user->can('instituciones.gestionar');
    }
}
