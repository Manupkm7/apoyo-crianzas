<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\LocalityResource;
use App\Http\Resources\ProvinceResource;
use App\Models\Department;
use App\Models\Institution;
use App\Models\Province;
use App\Support\Sector;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GeoController — Catálogo geográfico público (provincia → departamento →
 * localidad) y de sectores, usado por el login institucional para armar los
 * desplegables en cascada antes de elegir la institución.
 *
 * Todos los endpoints son públicos y de solo lectura: no exponen ningún dato
 * sensible, solo el catálogo geográfico y la lista fija de tipos de institución.
 */
class GeoController extends Controller
{
    public function provinces(): AnonymousResourceCollection
    {
        return ProvinceResource::collection(Province::orderBy('name')->get());
    }

    public function departments(Province $province): AnonymousResourceCollection
    {
        return DepartmentResource::collection(
            $province->departments()->orderBy('name')->get()
        );
    }

    public function localities(Department $department): AnonymousResourceCollection
    {
        return LocalityResource::collection(
            $department->localities()->orderBy('name')->get()
        );
    }

    /**
     * "Sector" = institutions.type (misma cosa) — ver App\Support\Sector.
     * 'justicia' se sigue ofreciendo en el login mientras haya instituciones
     * heredadas de ese tipo, para que puedan seguir entrando.
     */
    public function sectors(): array
    {
        $options = Sector::options();

        if (Institution::where('type', 'justicia')->where('is_active', true)->exists()) {
            $options[] = ['value' => 'justicia', 'label' => Sector::label('justicia')];
        }

        return ['data' => $options];
    }
}
