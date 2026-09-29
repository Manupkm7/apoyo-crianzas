<?php

namespace App\Policies;

use App\Contracts\SystemActor;
use App\Models\Child;
use App\Models\ChildService;

/**
 * ChildServicePolicy — prestaciones por período de un niño.
 *
 * - Admin: todo, sobre cualquier efector (ver admin_unrestricted).
 * - Coordinador: solo lectura (todas).
 * - Institución / representante: ve, carga y corrige SOLO las prestaciones en
 *   las que su institución es el efector, y solo de niños que ya puede ver
 *   (ChildPolicy::view). Nunca ve las de otros efectores: pueden traer datos
 *   sensibles de otros sectores (ej. situación laboral del hogar).
 * - Borrar: solo admin.
 */
class ChildServicePolicy
{
    public function viewAny(SystemActor $user, Child $child): bool
    {
        if ($user->canBypassRls()) {
            return true;
        }

        return (new ChildPolicy())->view($user, $child);
    }

    public function create(SystemActor $user, Child $child): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->isInstitutionalUser()
            && $user->can('ninos.gestionar')
            && (new ChildPolicy())->view($user, $child);
    }

    public function update(SystemActor $user, ChildService $service): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return $user->isInstitutionalUser()
            && $user->can('ninos.gestionar')
            && $service->institution_id === $user->institution_id;
    }

    public function delete(SystemActor $user, ChildService $service): bool
    {
        return $user->hasRole('admin');
    }
}
