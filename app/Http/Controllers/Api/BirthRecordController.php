<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBirthRecordRequest;
use App\Http\Requests\UpdateBirthRecordRequest;
use App\Http\Resources\BirthRecordResource;
use App\Models\BirthRecord;
use App\Models\Child;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BirthRecordController — ABM del registro de nacimiento de un niño.
 *
 * A diferencia de EducationRecord/HealthRecord, este registro no está acotado
 * por institución: cada niño tiene UN único registro de nacimiento, generado
 * mayormente por el sistema de importación masiva. Este controlador cubre la
 * carga y corrección manual, exclusiva del administrador (ver BirthRecordPolicy).
 *
 *   GET    /children/{child}/birth-record   → ver el registro
 *   POST   /children/{child}/birth-record   → crear el registro (solo admin)
 *   PATCH  /children/{child}/birth-record   → corregir el registro (solo admin)
 *   DELETE /children/{child}/birth-record   → dar de baja (solo admin)
 */
class BirthRecordController extends Controller
{
    public function show(Request $request, Child $child): JsonResponse
    {
        $this->authorize('viewAny', BirthRecord::class);

        $record = $child->birthRecord()->with('institution')->first();

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro de nacimiento cargado.'], 404);
        }

        return response()->json(new BirthRecordResource($record));
    }

    public function store(StoreBirthRecordRequest $request, Child $child): JsonResponse
    {
        $this->authorize('create', BirthRecord::class);

        if ($child->birthRecord()->exists()) {
            return response()->json([
                'message' => 'Este niño ya tiene un registro de nacimiento cargado.',
            ], 409);
        }

        $data = $request->validated();

        if (! empty($data['mother_dni'])) {
            $data['mother_dni_hash'] = hash('sha256', $data['mother_dni']);
        }
        if (! empty($data['father_dni'])) {
            $data['father_dni_hash'] = hash('sha256', $data['father_dni']);
        }

        $record = BirthRecord::create([
            ...$data,
            'child_id'   => $child->id,
            'created_by' => $request->user()->id,
        ]);

        return (new BirthRecordResource($record))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateBirthRecordRequest $request, Child $child): JsonResponse
    {
        $record = $child->birthRecord()->firstOrFail();

        $this->authorize('update', $record);

        $data = $request->validated();

        if (array_key_exists('mother_dni', $data)) {
            $data['mother_dni_hash'] = ! empty($data['mother_dni']) ? hash('sha256', $data['mother_dni']) : null;
        }
        if (array_key_exists('father_dni', $data)) {
            $data['father_dni_hash'] = ! empty($data['father_dni']) ? hash('sha256', $data['father_dni']) : null;
        }

        $record->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        return response()->json(new BirthRecordResource($record));
    }

    public function destroy(Request $request, Child $child): JsonResponse
    {
        $record = $child->birthRecord()->firstOrFail();

        $this->authorize('delete', $record);

        $record->update(['updated_by' => $request->user()->id]);
        $record->delete();

        return response()->json([
            'message' => 'Registro de nacimiento desactivado. Los datos se conservan en el historial.',
        ]);
    }
}
