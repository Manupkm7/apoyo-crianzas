<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHealthObservationRequest;
use App\Http\Resources\HealthObservationResource;
use App\Models\Child;
use App\Models\HealthObservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HealthObservationController — Bitácora de observaciones de un registro de salud.
 *
 * Cada entrada queda con autor y fecha, y opcionalmente un PDF adjunto guardado
 * en el disco 'local' (privado). El archivo nunca se sirve por URL pública:
 * siempre pasa por downloadAttachment(), que reverifica el acceso al registro.
 *
 * Solo la institución de salud responsable puede CREAR entradas (no representantes).
 * La verificación granular la hace HealthRecordPolicy::update().
 */
class HealthObservationController extends Controller
{
    public function index(Request $request, Child $child): JsonResponse
    {
        $user   = $request->user();
        $record = $user->canBypassRls()
            ? $child->healthRecord()->first()
            : $child->healthRecord()->where('institution_id', $user->institution_id)->first();

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro de salud.'], 404);
        }

        $this->authorize('view', $record);

        $observations = $record->observationEntries()->with('author')->get();

        return response()->json(HealthObservationResource::collection($observations));
    }

    public function store(StoreHealthObservationRequest $request, Child $child): JsonResponse
    {
        $user   = $request->user();
        $record = $user->canBypassRls()
            ? $child->healthRecord()->first()
            : $child->healthRecord()->where('institution_id', $user->institution_id)->first();

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro de salud.'], 404);
        }

        $this->authorize('update', $record);

        // Solo la institución (no el representante) puede agregar observaciones.
        // La verificación de rol se delega a la Policy — si el usuario es representante
        // y la Policy solo permite institucion/admin, devuelve 403 automáticamente.

        $data = [
            'health_record_id' => $record->id,
            'author_id'         => $user->getKey(),
            'author_type'       => $user->getMorphClass(),
            'body'              => $request->validated('body'),
        ];

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $data['attachment_path']          = $file->store('health-observations/'.$record->id, 'local');
            $data['attachment_original_name'] = $file->getClientOriginalName();
            $data['attachment_mime']          = $file->getClientMimeType();
            $data['attachment_size']          = $file->getSize();
        }

        $observation = HealthObservation::create($data);

        return response()->json(new HealthObservationResource($observation->load('author')), 201);
    }

    public function downloadAttachment(Request $request, HealthObservation $observation): StreamedResponse
    {
        $record = $observation->healthRecord;

        $this->authorize('view', $record);

        abort_unless($observation->attachment_path, 404);

        return Storage::disk('local')->download(
            $observation->attachment_path,
            $observation->attachment_original_name
        );
    }
}
