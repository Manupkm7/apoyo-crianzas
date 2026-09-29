<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Models\ProgramArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ProgramAreaController — catálogo de dependencias programáticas (áreas de
 * gobierno de las que depende un efector).
 *
 *   GET  /program-areas   → listar (quien puede ver instituciones)
 *   POST /program-areas   → agregar una nueva [solo quien gestiona instituciones]
 *
 * Si se intenta agregar un nombre que ya existe (sin importar mayúsculas,
 * tildes ni espacios), se devuelve el existente en vez de duplicarlo.
 */
class ProgramAreaController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Institution::class);

        $areas = ProgramArea::orderBy('name')->get(['id', 'name']);

        return response()->json(['data' => $areas]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can('instituciones.gestionar'),
            403,
            'No tiene permiso para agregar dependencias programáticas.'
        );

        $data = $request->validate(
            ['name' => ['required', 'string', 'min:2', 'max:200']],
            ['name.required' => 'Indicá el nombre de la dependencia programática.']
        );

        $existing = ProgramArea::where('name_normalized', ProgramArea::normalizeName($data['name']))->first();
        if ($existing) {
            return response()->json(['data' => $existing->only(['id', 'name'])]);
        }

        $area = ProgramArea::create([
            'name'       => $data['name'],
            'created_by' => $request->user()->auditId(),
        ]);

        return response()->json(['data' => $area->only(['id', 'name'])], 201);
    }
}
