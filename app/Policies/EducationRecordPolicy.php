<?php

namespace App\Policies;

use App\Models\EducationRecord;
use App\Models\User;

/**
 * EducationRecordPolicy — Define quién puede gestionar los registros educativos.
 *
 * Solo los usuarios de instituciones de tipo 'educacion' pueden crear y modificar
 * estos registros. Los registros educativos son completamente invisibles para
 * usuarios de instituciones de salud u otros tipos.
 *
 * Admin y coordinador tienen acceso de lectura a todos los registros.
 */
class EducationRecordPolicy
{
    /**
     * ¿Puede este usuario ver el listado de registros educativos?
     *
     * - Admin/coordinador: sí.
     * - Institución de educación: sí (el controlador filtra los de su institución).
     * - Otro tipo de institución: no.
     */
    public function viewAny(User $user): bool
    {
        if ($user->canBypassRls()) {
            return true;
        }

        return $user->isInstitutionalUser() && $user->institutionType() === 'educacion';
    }

    /**
     * ¿Puede este usuario ver un registro educativo específico?
     *
     * - Admin/coordinador: sí.
     * - Institución de educación: solo el registro de su propia institución.
     */
    public function view(User $user, EducationRecord $record): bool
    {
        if ($user->canBypassRls()) {
            return true;
        }

        return $user->institutionType() === 'educacion'
            && $record->institution_id === $user->institution_id;
    }

    /**
     * ¿Puede este usuario crear un registro educativo para un niño?
     *
     * Solo usuarios de instituciones de tipo educacion (y admin como caso especial).
     */
    public function create(User $user): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->isInstitutionalUser()
            && $user->institutionType() === 'educacion'
            && $user->can('ninos.gestionar');
    }

    /**
     * ¿Puede este usuario modificar un registro educativo?
     *
     * Solo la institución educativa dueña del registro puede modificarlo (o el admin).
     */
    public function update(User $user, EducationRecord $record): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->isInstitutionalUser()
            && $user->institutionType() === 'educacion'
            && $record->institution_id === $user->institution_id
            && $user->can('ninos.gestionar');
    }

    /**
     * ¿Puede este usuario eliminar un registro educativo?
     *
     * Solo el administrador. Los datos no se eliminan físicamente.
     */
    public function delete(User $user, EducationRecord $record): bool
    {
        return $user->hasRole('admin');
    }
}
