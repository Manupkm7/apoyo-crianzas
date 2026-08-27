<?php

namespace App\Policies;

use App\Contracts\SystemActor;
use App\Models\EducationRecord;

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
    public function viewAny(SystemActor $user): bool
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
    public function view(SystemActor $user, EducationRecord $record): bool
    {
        if ($user->canBypassRls()) {
            return true;
        }

        return $user->institutionType() === 'educacion'
            && $record->institution_id === $user->institution_id;
    }

    /**
     * ¿Puede este usuario vincular un niño a una institución educativa creando su
     * registro?
     *
     * SOLO el admin. Una institución no puede "adoptar" por su cuenta un niño que
     * ya existe en el sistema — decidir a qué institución pertenece un niño es
     * competencia del admin (lo hace desde el perfil del niño).
     *
     * El caso de una institución que registra un niño NUEVO se resuelve aparte:
     * ChildController::store le crea el registro mínimo de su sector directamente
     * (auto-vínculo), sin pasar por esta Policy.
     */
    public function create(SystemActor $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * ¿Puede este usuario modificar un registro educativo?
     *
     * Solo la institución educativa dueña del registro puede modificarlo (o el admin).
     */
    public function update(SystemActor $user, EducationRecord $record): bool
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
    public function delete(SystemActor $user, EducationRecord $record): bool
    {
        return $user->hasRole('admin');
    }
}
