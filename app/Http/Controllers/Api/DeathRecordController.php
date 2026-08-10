<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeathRecordRequest;
use App\Http\Requests\UpdateDeathRecordRequest;
use App\Http\Resources\DeathRecordResource;
use App\Models\Child;
use App\Models\DeathRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DeathRecordController — ABM del registro de defunción de un niño.
 *
 * Mismo criterio que BirthRecordController: un único registro por niño,
 * generado mayormente por importación masiva; la carga/corrección manual
 * es exclusiva del administrador (ver DeathRecordPolicy).
 *
 *   GET    /children/{child}/death-record   → ver el registro
 *   POST   /children/{child}/death-record   → crear el registro (solo admin)
 *   PATCH  /children/{child}/death-record   → corregir el registro (solo admin)
 *   DELETE /children/{child}/death-record   → dar de baja (solo admin)
 */
class DeathRecordController extends Controller
{
    public function show(Request $request, Child $child): JsonResponse
    {
        $this->authorize('viewAny', DeathRecord::class);

        $record = $child->deathRecord()->with('institution')->first();

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro de defunción cargado.'], 404);
        }

        return response()->json(new DeathRecordResource($record));
    }

    public function store(StoreDeathRecordRequest $request, Child $child): JsonResponse
    {
        $this->authorize('create', DeathRecord::class);

        if ($child->deathRecord()->exists()) {
            return response()->json([
                'message' => 'Este niño ya tiene un registro de defunción cargado.',
            ], 409);
        }

        $data = $request->validated();
        $data['mother_dni_hash'] = hash('sha256', $data['mother_dni']);

        if (! empty($data['child_dni'])) {
            $data['child_dni_hash'] = hash('sha256', $data['child_dni']);
        }

        $record = DeathRecord::create([
            ...$data,
            'child_id'   => $child->id,
            'created_by' => $request->user()->id,
        ]);

        return (new DeathRecordResource($record))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateDeathRecordRequest $request, Child $child): JsonResponse
    {
        $record = $child->deathRecord()->firstOrFail();

        $this->authorize('update', $record);

        $data = $request->validated();

        if (array_key_exists('mother_dni', $data) && ! empty($data['mother_dni'])) {
            $data['mother_dni_hash'] = hash('sha256', $data['mother_dni']);
        }
        if (array_key_exists('child_dni', $data)) {
            $data['child_dni_hash'] = ! empty($data['child_dni']) ? hash('sha256', $data['child_dni']) : null;
        }

        $record->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(new DeathRecordResource($record));
    }

    public function destroy(Request $request, Child $child): JsonResponse
    {
        $record = $child->deathRecord()->firstOrFail();

        $this->authorize('delete', $record);

        $record->update(['updated_by' => $request->user()->id]);
        $record->delete();

        return response()->json([
            'message' => 'Registro de defunción desactivado. Los datos se conservan en el historial.',
        ]);
    }
}
