<?php

namespace App\Policies;

use App\Contracts\SystemActor;
use App\Models\BirthRecord;

/**
 * BirthRecordPolicy — Define quién puede gestionar los registros de nacimiento.
 *
 * A diferencia de EducationRecord/HealthRecord, estos registros no están
 * acotados a un tipo de institución: la mayoría se generan automáticamente
 * por el sistema de importación masiva (ImportRow → BirthRecord), y la
 * carga/edición manual queda reservada al administrador como excepción
 * (correcciones, altas puntuales).
 *
 * Solo admin y coordinador pueden ver estos registros (contienen DNI y
 * datos de adultos terceros — la madre y el padre — que son PII sensible).
 */
class BirthRecordPolicy
{
    public function viewAny(SystemActor $user): bool
    {
        return $user->canBypassRls();
    }

    public function view(SystemActor $user, BirthRecord $record): bool
    {
        return $user->canBypassRls();
    }

    public function create(SystemActor $user): bool
    {
        return $user->hasRole('admin');
    }

    public function update(SystemActor $user, BirthRecord $record): bool
    {
        return $user->hasRole('admin');
    }

    public function delete(SystemActor $user, BirthRecord $record): bool
    {
        return $user->hasRole('admin');
    }
}
