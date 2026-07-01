<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHealthRecordRequest;
use App\Http\Requests\UpdateHealthRecordRequest;
use App\Http\Resources\HealthRecordResource;
use App\Models\Child;
use App\Models\HealthRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HealthRecordController — ABM del registro de salud de un niño.
 *
 * Cada niño tiene UN único registro de salud por institución.
 * Este controlador trabaja siempre bajo un niño específico:
 *   GET    /children/{child}/health-record   → ver el registro de salud
 *   POST   /children/{child}/health-record   → crear el registro
 *   PATCH  /children/{child}/health-record   → modificar el registro
 *   DELETE /children/{child}/health-record   → dar de baja el registro
 *
 * Solo los usuarios de instituciones de tipo 'salud' (y el admin) pueden operar aquí.
 * Los usuarios de educación u otros tipos reciben un 403 Forbidden.
 */
class HealthRecordController extends Controller
{
    /**
     * Devuelve el registro de salud de un niño específico.
     *
     * - Si el usuario es admin/coordinador: ve el registro con datos de la institución.
     * - Si es una institución de salud: ve el registro de su institución para ese niño.
     */
    public function show(Request $request, Child $child): JsonResponse
    {
        $user = $request->user();

        $record = $user->canBypassRls()
            ? $child->healthRecord()->with('institution')->first()
            : $child->healthRecord()->where('institution_id', $user->institution_id)->with('institution')->first();

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro de salud en tu institución.'], 404);
        }

        $this->authorize('view', $record);

        return response()->json(new HealthRecordResource($record));
    }

    /**
     * Crea el registro de salud de un niño para la institución del usuario.
     *
     * Si ya existe un registro para este niño en esta institución, devuelve 409 Conflict.
     * La policy verifica que el usuario sea de una institución de tipo 'salud'.
     */
    public function store(StoreHealthRecordRequest $request, Child $child): JsonResponse
    {
        $this->authorize('create', HealthRecord::class);

        $institutionId = $request->user()->institution_id;

        // Un niño solo puede tener un registro por institución de salud
        if ($child->healthRecord()->where('institution_id', $institutionId)->exists()) {
            return response()->json([
                'message' => 'Ya existe un registro de salud de este niño para tu institución.',
            ], 409);
        }

        $record = HealthRecord::create([
            ...$request->validated(),
            'child_id'       => $child->id,
            'institution_id' => $institutionId,
            'created_by'     => $request->user()->id,
        ]);

        return (new HealthRecordResource($record))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Actualiza el registro de salud de un niño.
     *
     * Actualización parcial: solo se modifican los campos enviados.
     * Solo la institución dueña del registro puede modificarlo (o el admin).
     */
    public function update(UpdateHealthRecordRequest $request, Child $child): JsonResponse
    {
        $user   = $request->user();
        $record = $user->canBypassRls()
            ? $child->healthRecord()->firstOrFail()
            : $child->healthRecord()->where('institution_id', $user->institution_id)->firstOrFail();

        $this->authorize('update', $record);

        $record->update([
            ...$request->validated(),
            'updated_by' => $user->id,
        ]);

        return response()->json(new HealthRecordResource($record));
    }

    /**
     * Da de baja (soft delete) el registro de salud de un niño.
     *
     * Solo el administrador puede hacer esto.
     */
    public function destroy(Request $request, Child $child): JsonResponse
    {
        $record = $child->healthRecord()->firstOrFail();

        $this->authorize('delete', $record);

        $record->update(['updated_by' => $request->user()->id]);
        $record->delete();

        return response()->json([
            'message' => 'Registro de salud desactivado. Los datos se conservan en el historial.',
        ]);
    }
}
