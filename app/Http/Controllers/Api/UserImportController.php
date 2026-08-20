<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveUserImportRowRequest;
use App\Http\Requests\StoreUserImportRequest;
use App\Http\Resources\UserImportBatchResource;
use App\Http\Resources\UserImportRowResource;
use App\Jobs\ProcessUserImportBatch;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use App\Services\Import\ImportTemplateService;
use App\Services\Import\UserImportRowProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\File;

/**
 * UserImportController — carga masiva de usuarios institucionales (rol
 * 'institucion' o 'representante') desde un archivo CSV, TXT o Excel.
 *
 * Acceso:
 *   - Admin ('usuarios.gestionar') y coordinador ('usuarios.carga_masiva'):
 *     cualquier institución, cualquiera de los dos roles — ver
 *     User::hasFullUserImportAccess().
 *   - Responsable de institución ('representantes.gestionar'): solo su
 *     propia institución; las filas que pidan rol 'institucion' quedan en
 *     revisión con motivo 'invalid_role' (no tiene permiso para asignarlo).
 */
class UserImportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $batches = UserImportBatch::with(['institution', 'uploader'])
            ->when(
                ! $user->hasFullUserImportAccess(),
                fn ($q) => $q->where('institution_id', $user->institution_id)
            )
            ->latest()
            ->paginate(15);

        return UserImportBatchResource::collection($batches);
    }

    public function show(Request $request, UserImportBatch $batch): UserImportBatchResource
    {
        $this->authorizeAccessToBatch($request, $batch);

        $batch->load(['institution', 'uploader']);

        return new UserImportBatchResource($batch);
    }

    public function store(StoreUserImportRequest $request): JsonResponse
    {
        $file = $request->file('file');

        $storagePath = $file->store('user-imports/' . now()->format('Y/m'), 'local');

        $batch = UserImportBatch::create([
            'institution_id'    => $request->input('institution_id'),
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_by'       => $request->user()->id,
            'status'            => 'pending',
        ]);

        ProcessUserImportBatch::dispatch($batch->id, $storagePath, $this->rolesAllowedFor($request->user()));

        $batch->load(['institution', 'uploader']);

        return (new UserImportBatchResource($batch))
            ->response()
            ->setStatusCode(202);
    }

    public function template(Request $request, ImportTemplateService $templates): BinaryFileResponse|StreamedResponse
    {
        abort_unless(
            $request->user()->hasFullUserImportAccess() || $request->user()->can('representantes.gestionar'),
            403,
            'No tiene permiso para descargar la plantilla de carga de usuarios.'
        );

        $data = $request->validate([
            'format' => ['required', 'in:xlsx,csv,txt'],
        ]);

        $format = $data['format'];
        $filename = $templates->filenameStem('users') . '.' . $format;

        if ($format === 'xlsx') {
            $dir = storage_path('app/private/exports');
            File::ensureDirectoryExists($dir);
            $path = $dir . DIRECTORY_SEPARATOR . $filename;

            (new Xlsx($templates->buildSpreadsheet('users')))->save($path);

            return response()->download($path, $filename)->deleteFileAfterSend(true);
        }

        $delimiter = $format === 'txt' ? '|' : ',';
        $content = $templates->buildDelimited('users', $delimiter);
        $mime = $format === 'txt' ? 'text/plain' : 'text/csv';

        return response()->streamDownload(
            fn () => print($content),
            $filename,
            ['Content-Type' => "{$mime}; charset=UTF-8"]
        );
    }

    /**
     * Filas de un lote que necesitan revisión manual.
     */
    public function rows(Request $request, UserImportBatch $batch): AnonymousResourceCollection
    {
        $this->authorizeAccessToBatch($request, $batch);

        $status = $request->query('status', 'needs_review');
        $validStatuses = ['pending', 'created', 'needs_review', 'skipped', 'error'];

        $query = $batch->rows()->with(['createdUser']);

        if (in_array($status, $validStatuses, true)) {
            $query->where('status', $status);
        }

        $rows = $query->orderBy('file_line_number')->paginate(20);

        return UserImportRowResource::collection($rows);
    }

    /**
     * Resolución manual de una fila.
     *
     * action=confirm → vuelve a correr la misma validación/creación que el job
     *                  automático (UserImportRowProcessor). Si el conflicto que
     *                  la mandó a revisión ya no existe, crea el usuario.
     * action=skip    → descarta la fila; no se crea ningún usuario.
     */
    public function resolveRow(
        ResolveUserImportRowRequest $request,
        UserImportBatch $batch,
        UserImportRow $row,
        UserImportRowProcessor $processor,
    ): JsonResponse {
        $this->authorizeAccessToBatch($request, $batch);

        if ($row->batch_id !== $batch->id) {
            abort(404);
        }

        if ($row->status !== 'needs_review') {
            return response()->json([
                'message' => 'Esta fila ya fue procesada (estado: ' . $row->status . ').',
            ], 422);
        }

        $action = $request->input('action');
        $userId = $request->user()->id;

        if ($action === 'skip') {
            $row->update([
                'status'      => 'skipped',
                'resolved_by' => $userId,
                'resolved_at' => now(),
            ]);

            $row->refresh()->load('createdUser');

            return response()->json([
                'message' => 'Fila descartada correctamente.',
                'row'     => new UserImportRowResource($row),
            ]);
        }

        $raw = json_decode($row->raw_data, true) ?: [];
        $result = $processor->process(
            $raw,
            $batch->institution_id,
            $batch->uploaded_by,
            $this->rolesAllowedFor($request->user()),
        );

        if (! $result->success) {
            $row->update([
                'review_reason' => $result->reviewReason,
                'notes'         => $result->notes,
            ]);

            return response()->json([
                'message' => 'El conflicto sigue vigente: ' . $result->notes,
                'row'     => new UserImportRowResource($row->refresh()),
            ], 422);
        }

        $row->update([
            'status'           => 'created',
            'created_user_id'  => $result->userId,
            'resolved_by'      => $userId,
            'resolved_at'      => now(),
        ]);

        $row->refresh()->load('createdUser');

        return response()->json([
            'message' => 'Usuario creado correctamente.',
            'row'     => new UserImportRowResource($row),
        ]);
    }

    // ─── Autorización helper ──────────────────────────────────────────────────────

    private function authorizeAccessToBatch(Request $request, UserImportBatch $batch): void
    {
        $user = $request->user();

        if ($user->hasFullUserImportAccess()) {
            return;
        }

        abort_unless(
            $user->can('representantes.gestionar') && $batch->institution_id === $user->institution_id,
            403,
            'No tiene permiso para ver esta importación.'
        );
    }

    /**
     * Roles que el usuario autenticado tiene permiso de asignar vía carga masiva.
     * Admin y coordinador: ambos. Responsable de institución: solo representante
     * (nunca puede crear otro 'institucion', ni siquiera en su propia institución).
     */
    private function rolesAllowedFor(User $user): array
    {
        return $user->hasFullUserImportAccess()
            ? ['institucion', 'representante']
            : ['representante'];
    }
}
