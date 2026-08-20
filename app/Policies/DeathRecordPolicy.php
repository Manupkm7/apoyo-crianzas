<?php

namespace App\Policies;

use App\Contracts\SystemActor;
use App\Models\DeathRecord;

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
    public function viewAny(SystemActor $user): bool
    {
        return $user->canBypassRls();
    }

    public function view(SystemActor $user, DeathRecord $record): bool
    {
        return $user->canBypassRls();
    }

    public function create(SystemActor $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(SystemActor $user, DeathRecord $record): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(SystemActor $user, DeathRecord $record): bool
    {
        return $user->hasRole('admin');
    }
}
