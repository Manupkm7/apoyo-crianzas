<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstitutionDirectoryResource;
use App\Models\Institution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * InstitutionDirectoryController — Desplegable público de instituciones para
 * el login institucional, filtrado por localidad y sector (institutions.type).
 *
 * Público y de solo lectura: solo devuelve id/name/type de instituciones
 * activas (InstitutionDirectoryResource), nunca contraseña ni contacto.
 */
class InstitutionDirectoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'locality_id' => ['required', 'uuid', 'exists:localities,id'],
            'type' => ['required', Rule::in(['salud', 'educacion', 'desarrollo_social', 'justicia', 'otro'])],
        ]);

        $institutions = Institution::query()
            ->where('locality_id', $request->query('locality_id'))
            ->where('type', $request->query('type'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return InstitutionDirectoryResource::collection($institutions);
    }
}
