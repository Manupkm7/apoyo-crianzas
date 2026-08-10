<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEducationObservationRequest;
use App\Http\Resources\EducationObservationResource;
use App\Models\Child;
use App\Models\EducationObservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EducationObservationController — Bitácora de observaciones de un registro educativo.
 *
 * Cada entrada queda con autor y fecha, y opcionalmente un PDF adjunto guardado
 * en el disco 'local' (privado). El archivo nunca se sirve por URL pública:
 * siempre pasa por downloadAttachment(), que reverifica el acceso al registro.
 */
class EducationObservationController extends Controller
{
    public function index(Request $request, Child $child): JsonResponse
    {
        $user   = $request->user();
        $record = $user->canBypassRls()
            ? $child->educationRecord()->first()
            : $child->educationRecord()->where('institution_id', $user->institution_id)->first();

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro educativo.'], 404);
        }

        $this->authorize('view', $record);

        $observations = $record->observationEntries()->with('author')->get();

        return response()->json(EducationObservationResource::collection($observations));
    }

    public function store(StoreEducationObservationRequest $request, Child $child): JsonResponse
    {
        $user   = $request->user();
        $record = $user->canBypassRls()
            ? $child->educationRecord()->first()
            : $child->educationRecord()->where('institution_id', $user->institution_id)->first();

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro educativo.'], 404);
        }

        $this->authorize('update', $record);

        $data = [
            'education_record_id' => $record->id,
            'author_id'            => $user->id,
            'body'                 => $request->validated('body'),
        ];

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $data['attachment_path']          = $file->store('education-observations/'.$record->id, 'local');
            $data['attachment_original_name'] = $file->getClientOriginalName();
            $data['attachment_mime']          = $file->getClientMimeType();
            $data['attachment_size']          = $file->getSize();
        }

        $observation = EducationObservation::create($data);

        // Bare object (no envoltorio 'data'), igual que index() — el frontend
        // consume ambos endpoints de la misma forma.
        return response()->json(new EducationObservationResource($observation->load('author')), 201);
    }

    public function downloadAttachment(Request $request, EducationObservation $observation): StreamedResponse
    {
        $record = $observation->educationRecord;

        $this->authorize('view', $record);

        abort_unless($observation->attachment_path, 404);

        return Storage::disk('local')->download(
            $observation->attachment_path,
            $observation->attachment_original_name
        );
    }
}
