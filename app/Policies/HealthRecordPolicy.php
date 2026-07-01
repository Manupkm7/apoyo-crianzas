<?php

namespace App\Policies;

use App\Models\HealthRecord;
use App\Models\User;

/**
 * HealthRecordPolicy — Define quién puede gestionar los registros de salud.
 *
 * Solo los usuarios de instituciones de tipo 'salud' pueden crear y modificar
 * estos registros. Los registros de salud son completamente invisibles para
 * usuarios de instituciones educativas u otros tipos.
 *
 * Admin y coordinador tienen acceso de lectura a todos los registros.
 */
class HealthRecordPolicy
{
    /**
     * ¿Puede este usuario ver el listado de registros de salud?
     *
     * - Admin/coordinador: sí.
     * - Institución de salud: sí (el controlador filtra los de su institución).
     * - Otro tipo de institución: no.
     */
    public function viewAny(User $user): bool
    {
        if ($user->canBypassRls()) {
            return true;
        }

        return $user->isInstitutionalUser() && $user->institutionType() === 'salud';
    }

    /**
     * ¿Puede este usuario ver un registro de salud específico?
     *
     * - Admin/coordinador: sí.
     * - Institución de salud: solo el registro de su propia institución.
     */
    public function view(User $user, HealthRecord $record): bool
    {
        if ($user->canBypassRls()) {
            return true;
        }

        return $user->institutionType() === 'salud'
            && $record->institution_id === $user->institution_id;
    }

    /**
     * ¿Puede este usuario crear un registro de salud para un niño?
     *
     * Solo usuarios de instituciones de tipo salud (y admin como caso especial).
     */
    public function create(User $user): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->isInstitutionalUser()
            && $user->institutionType() === 'salud'
            && $user->can('ninos.gestionar');
    }

    /**
     * ¿Puede este usuario modificar un registro de salud?
     *
     * Solo la institución de salud dueña del registro puede modificarlo (o el admin).
     */
    public function update(User $user, HealthRecord $record): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->isInstitutionalUser()
            && $user->institutionType() === 'salud'
            && $record->institution_id === $user->institution_id
            && $user->can('ninos.gestionar');
    }

    /**
     * ¿Puede este usuario eliminar un registro de salud?
     *
     * Solo el administrador. Los datos no se eliminan físicamente.
     */
    public function delete(User $user, HealthRecord $record): bool
    {
        return $user->hasRole('admin');
    }
}
