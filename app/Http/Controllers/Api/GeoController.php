<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\LocalityResource;
use App\Http\Resources\ProvinceResource;
use App\Models\Department;
use App\Models\Province;
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
     * "Sector" reutiliza el enum ya existente de institutions.type — no hay
     * una tabla de sectores separada (ver StoreInstitutionRequest::rules()).
     */
    public function sectors(): array
    {
        return [
            'data' => [
                ['value' => 'salud', 'label' => 'Salud'],
                ['value' => 'educacion', 'label' => 'Educación'],
                ['value' => 'desarrollo_social', 'label' => 'Desarrollo Social'],
                ['value' => 'justicia', 'label' => 'Justicia'],
                ['value' => 'otro', 'label' => 'Otro'],
            ],
        ];
    }
}
