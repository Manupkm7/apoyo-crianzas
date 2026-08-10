<?php

namespace App\Policies;

use App\Models\DeathRecord;
use App\Models\User;

/**
 * DeathRecordPolicy — Define quién puede gestionar los registros de defunción.
 *
 * Mismo criterio que BirthRecordPolicy: no está acotado por tipo de institución,
 * se genera mayormente vía importación masiva, y la carga/edición manual es
 * exclusiva del administrador.
 *
 * cause_of_death es el dato más sensible del sistema — por eso view/viewAny
 * se restringen a admin/coordinador únicamente.
 */
class DeathRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canBypassRls();
    }

    public function view(User $user, DeathRecord $record): bool
    {
        return $user->canBypassRls();
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(User $user, DeathRecord $record): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(User $user, DeathRecord $record): bool
    {
        return $user->hasRole('admin');
    }
}
