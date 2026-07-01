<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEducationRecordRequest;
use App\Http\Requests\UpdateEducationRecordRequest;
use App\Http\Resources\EducationRecordResource;
use App\Models\Child;
use App\Models\EducationRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EducationRecordController — ABM del registro educativo de un niño.
 *
 * Cada niño tiene UN único registro educativo por institución.
 * Este controlador trabaja siempre bajo un niño específico:
 *   GET    /children/{child}/education-record   → ver el registro educativo
 *   POST   /children/{child}/education-record   → crear el registro educativo
 *   PATCH  /children/{child}/education-record   → modificar el registro
 *   DELETE /children/{child}/education-record   → dar de baja el registro
 *
 * Solo los usuarios de instituciones de tipo 'educacion' (y el admin) pueden operar aquí.
 * Los usuarios de salud u otros tipos reciben un 403 Forbidden.
 */
class EducationRecordController extends Controller
{
    /**
     * Devuelve el registro educativo de un niño específico.
     *
     * - Si el usuario es admin/coordinador: ve el registro con datos de la institución.
     * - Si es una institución educativa: ve el registro de su institución para ese niño.
     */
    public function show(Request $request, Child $child): JsonResponse
    {
        $user = $request->user();

        // Buscamos el registro según el contexto del usuario
        $record = $user->canBypassRls()
            ? $child->educationRecord()->with('institution')->first()
            : $child->educationRecord()->where('institution_id', $user->institution_id)->with('institution')->first();

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro educativo en tu institución.'], 404);
        }

        $this->authorize('view', $record);

        return response()->json(new EducationRecordResource($record));
    }

    /**
     * Crea el registro educativo de un niño para la institución del usuario.
     *
     * Si ya existe un registro para este niño en esta institución, devuelve 409 Conflict.
     * La policy verifica que el usuario sea de una institución de tipo 'educacion'.
     */
    public function store(StoreEducationRecordRequest $request, Child $child): JsonResponse
    {
        $this->authorize('create', EducationRecord::class);

        $institutionId = $request->user()->institution_id;

        // Un niño solo puede tener un registro por institución educativa
        if ($child->educationRecord()->where('institution_id', $institutionId)->exists()) {
            return response()->json([
                'message' => 'Ya existe un registro educativo de este niño para tu institución.',
            ], 409);
        }

        $record = EducationRecord::create([
            ...$request->validated(),
            'child_id'       => $child->id,
            'institution_id' => $institutionId,
            'created_by'     => $request->user()->id,
        ]);

        return (new EducationRecordResource($record))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Actualiza el registro educativo de un niño.
     *
     * Actualización parcial: solo se modifican los campos enviados.
     * Solo la institución dueña del registro puede modificarlo (o el admin).
     */
    public function update(UpdateEducationRecordRequest $request, Child $child): JsonResponse
    {
        $user   = $request->user();
        $record = $user->canBypassRls()
            ? $child->educationRecord()->firstOrFail()
            : $child->educationRecord()->where('institution_id', $user->institution_id)->firstOrFail();

        $this->authorize('update', $record);

        $record->update([
            ...$request->validated(),
            'updated_by' => $user->id,
        ]);

        return response()->json(new EducationRecordResource($record));
    }

    /**
     * Da de baja (soft delete) el registro educativo de un niño.
     *
     * Solo el administrador puede hacer esto.
     */
    public function destroy(Request $request, Child $child): JsonResponse
    {
        $record = $child->educationRecord()->firstOrFail();

        $this->authorize('delete', $record);

        $record->update(['updated_by' => $request->user()->id]);
        $record->delete();

        return response()->json([
            'message' => 'Registro educativo desactivado. Los datos se conservan en el historial.',
        ]);
    }
}
