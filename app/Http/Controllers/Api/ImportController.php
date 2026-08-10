<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveImportRowRequest;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportBatchResource;
use App\Http\Resources\ImportRowResource;
use App\Jobs\ProcessImportBatch;
use App\Models\BirthRecord;
use App\Models\Child;
use App\Models\EducationRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Services\Import\ImportMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ImportController — gestión de importaciones masivas (Registro Civil y Educación).
 *
 * Acceso:
 *   - index, show, rows → admin, coordinador (reportes.ver | importaciones.gestionar)
 *   - store, resolveRow → solo admin (importaciones.gestionar)
 */
class ImportController extends Controller
{
    /**
     * Listado paginado de lotes de importación, más recientes primero.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        $batches = ImportBatch::with(['institution', 'uploader'])
            ->latest()
            ->paginate(15);

        return ImportBatchResource::collection($batches);
    }

    /**
     * Detalle de un lote específico.
     */
    public function show(Request $request, ImportBatch $batch): ImportBatchResource
    {
        $this->authorizeRead($request);

        $batch->load(['institution', 'uploader']);

        return new ImportBatchResource($batch);
    }

    /**
     * Recibe el archivo, lo guarda, crea el batch y despacha el job de procesamiento.
     *
     * El job corre en background (queue). La respuesta 202 indica que se aceptó
     * la solicitud y el procesamiento está en curso — no que ya terminó.
     */
    public function store(StoreImportRequest $request): JsonResponse
    {
        $file = $request->file('file');

        // Guardar el archivo en disco antes de despachar el job.
        // El job lo leerá desde storage y lo eliminará al finalizar.
        $storagePath = $file->store('imports/' . now()->format('Y/m'), 'local');

        $batch = ImportBatch::create([
            'source'            => $request->input('source'),
            'institution_id'    => $request->input('institution_id'),
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_by'       => $request->user()->id,
            'status'            => 'pending',
        ]);

        ProcessImportBatch::dispatch($batch->id, $storagePath);

        $batch->load(['institution', 'uploader']);

        return (new ImportBatchResource($batch))
            ->response()
            ->setStatusCode(202); // Accepted — procesamiento en curso
    }

    /**
     * Filas de un lote filtradas por estado.
     *
     * Query param ?status= → partial_match | no_match | matched | error | pending | skipped
     * Si no se provee, devuelve las que necesitan revisión (partial_match + no_match).
     */
    public function rows(Request $request, ImportBatch $batch): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        $status = $request->query('status');
        $validStatuses = ['pending', 'matched', 'partial_match', 'no_match', 'manual_resolved', 'skipped', 'error'];

        $query = $batch->rows()->with(['matchedRow', 'child', 'batch']);

        if ($status && in_array($status, $validStatuses)) {
            $query->where('status', $status);
        } else {
            // Default: filas que necesitan revisión del operador
            $query->whereIn('status', ['partial_match', 'no_match']);
        }

        $rows = $query
            ->orderByDesc('match_confidence') // las de mayor confianza primero (partial antes que no_match)
            ->paginate(20);

        return ImportRowResource::collection($rows);
    }

    /**
     * Resolución manual de una fila por parte del operador.
     *
     * action=confirm → vincula la fila a un niño existente (child_id) o crea uno nuevo.
     *                  También crea el registro de dominio (birth o education record).
     * action=skip    → descarta la fila; no genera ningún registro.
     */
    public function resolveRow(
        ResolveImportRowRequest $request,
        ImportBatch $batch,
        ImportRow $row,
    ): JsonResponse {
        // Verificar que la fila pertenece al batch indicado en la URL
        if ($row->batch_id !== $batch->id) {
            abort(404);
        }

        // Solo se pueden resolver filas que estén en estado de revisión pendiente
        if (! in_array($row->status, ['partial_match', 'no_match'])) {
            return response()->json([
                'message' => 'Esta fila ya fue procesada (estado: ' . $row->status . ').',
            ], 422);
        }

        $action = $request->input('action');

        if ($action === 'skip') {
            $this->skipRow($row, $request->user()->id);
        } else {
            $this->confirmRow($row, $request->input('child_id'), $request->user()->id, $batch);
        }

        $row->refresh()->load(['matchedRow', 'child', 'batch']);

        return response()->json([
            'message' => $action === 'skip'
                ? 'Fila descartada correctamente.'
                : 'Fila vinculada correctamente.',
            'row'     => new ImportRowResource($row),
        ]);
    }

    // ─── Lógica de resolución ─────────────────────────────────────────────────────

    private function skipRow(ImportRow $row, string $userId): void
    {
        DB::transaction(function () use ($row, $userId) {
            $row->update([
                'status'      => 'skipped',
                'resolved_by' => $userId,
                'resolved_at' => now(),
                'match_notes' => ($row->match_notes ? $row->match_notes . ' | ' : '') . 'Descartado manualmente.',
            ]);

            // Si la contraparte estaba vinculada a esta fila (partial_match), la dejamos
            // en no_match para que pueda ser resuelta por separado
            if ($row->matched_row_id) {
                ImportRow::where('id', $row->matched_row_id)
                    ->where('status', 'partial_match')
                    ->update([
                        'status'         => 'no_match',
                        'matched_row_id' => null,
                        'match_notes'    => 'Contraparte descartada por el operador. Sin coincidencia.',
                    ]);
            }
        });
    }

    private function confirmRow(ImportRow $row, ?string $childId, string $userId, ImportBatch $batch): void
    {
        DB::transaction(function () use ($row, $childId, $userId, $batch) {
            $raw = json_decode($row->raw_data, true) ?: [];

            // Obtener o crear el niño
            $child = $childId
                ? Child::findOrFail($childId)
                : $this->createChildFromRow($raw, $userId);

            // Crear el registro de dominio correspondiente a la fuente del batch
            if ($batch->source === 'civil_registry') {
                $this->createBirthRecordIfAbsent($child, $raw, $batch->institution_id);
            } else {
                $this->createEducationRecordIfAbsent($child, $raw, $batch->institution_id);
            }

            // Actualizar esta fila
            $row->update([
                'status'      => 'manual_resolved',
                'child_id'    => $child->id,
                'resolved_by' => $userId,
                'resolved_at' => now(),
            ]);

            // Si hay una contraparte (partial_match), también vincularla
            if ($row->matched_row_id) {
                $matchedRow = ImportRow::find($row->matched_row_id);
                if ($matchedRow && in_array($matchedRow->status, ['partial_match', 'no_match'])) {
                    $matchedRaw  = json_decode($matchedRow->raw_data, true) ?: [];
                    $matchedBatch = $matchedRow->batch;

                    if ($matchedBatch->source === 'civil_registry') {
                        $this->createBirthRecordIfAbsent($child, $matchedRaw, $matchedBatch->institution_id);
                    } else {
                        $this->createEducationRecordIfAbsent($child, $matchedRaw, $matchedBatch->institution_id);
                    }

                    $matchedRow->update([
                        'status'      => 'manual_resolved',
                        'child_id'    => $child->id,
                        'resolved_by' => $userId,
                        'resolved_at' => now(),
                    ]);
                }
            }
        });
    }

    // ─── Helpers de creación de registros ────────────────────────────────────────

    private function createChildFromRow(array $raw, string $createdBy): Child
    {
        return Child::create([
            'first_name' => $raw['first_name'] ?? 'Sin nombre',
            'last_name'  => $raw['last_name'] ?? 'Sin apellido',
            'birth_date' => $raw['birth_date'] ?? null,
            'created_by' => $createdBy,
        ]);
    }

    private function createBirthRecordIfAbsent(Child $child, array $raw, ?string $institutionId): void
    {
        if ($child->birthRecord()->exists()) {
            return;
        }

        $motherDni = $raw['mother_dni'] ?? null;
        $fatherDni = $raw['father_dni'] ?? null;

        BirthRecord::create([
            'child_id'            => $child->id,
            'institution_id'      => $institutionId,
            'first_name'          => $raw['first_name'] ?? '',
            'last_name'           => $raw['last_name'] ?? '',
            'birth_date'          => $raw['birth_date'] ?? null,
            'mother_name'         => $raw['mother_name'] ?? null,
            'mother_dni'          => $motherDni,
            'mother_dni_hash'     => $motherDni ? ImportMatchingService::hashDni($motherDni) : null,
            'father_name'         => $raw['father_name'] ?? null,
            'father_dni'          => $fatherDni,
            'father_dni_hash'     => $fatherDni ? ImportMatchingService::hashDni($fatherDni) : null,
            'address'             => $raw['address'] ?? null,
            'birth_establishment' => $raw['birth_establishment'] ?? null,
        ]);
    }

    private function createEducationRecordIfAbsent(Child $child, array $raw, ?string $institutionId): void
    {
        if (! isset($raw['school_name'])) {
            return;
        }

        EducationRecord::firstOrCreate(
            ['child_id' => $child->id],
            [
                'institution_id' => $institutionId,
                'school_name'    => $raw['school_name'],
                'grade_or_year'  => $raw['grade_or_year'] ?? null,
                'is_enrolled'    => true,
                'absences_count' => 0,
            ]
        );
    }

    // ─── Autorización helper ──────────────────────────────────────────────────────

    private function authorizeRead(Request $request): void
    {
        $user = $request->user();
        if (! $user->can('importaciones.gestionar') && ! $user->can('reportes.ver')) {
            abort(403, 'No tiene permiso para ver importaciones.');
        }
    }
}