<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewImportRequest;
use App\Http\Requests\ResolveImportRowRequest;
use App\Http\Requests\StoreImportRequest;
use App\Http\Resources\ImportBatchResource;
use App\Http\Resources\ImportRowResource;
use App\Jobs\ProcessImportBatch;
use App\Models\BirthRecord;
use App\Models\Child;
use App\Models\EducationRecord;
use App\Models\HealthRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\Institution;
use App\Services\Import\ImportMatchingService;
use App\Services\Import\ImportParserService;
use App\Services\Import\ImportTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     *
     * Un batch 'completed' sin nada pendiente de revisar (todas sus filas ya
     * quedaron matched/manual_resolved/skipped/error) deja de listarse acá —
     * pedido explícito del cliente para no acumular filas viejas en la
     * pantalla. El registro NO se borra de la base (sigue existiendo para
     * trazabilidad — ver ImportRow — y sigue siendo accesible entrando
     * directo a /imports/{id}/review por URL), solo se oculta del listado.
     * 'pending'/'processing'/'failed' siempre se listan, tengan o no filas
     * por revisar, porque necesitan atención o todavía están en curso.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        $batches = ImportBatch::with(['institution', 'uploader'])
            ->where(function ($query) {
                $query->whereIn('status', ['pending', 'processing', 'failed'])
                    ->orWhereHas('rows', fn ($q) => $q->whereIn('status', ['partial_match', 'no_match']));
            })
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
     * Otras hojas creadas a partir del mismo archivo subido (mismo storage_path),
     * para que el operador pueda elegir a mano contra cuál comparar en rematchBatch().
     */
    public function siblings(Request $request, ImportBatch $batch): AnonymousResourceCollection
    {
        $this->authorizeRead($request);

        $siblings = ImportBatch::where('storage_path', $batch->storage_path)
            ->where('id', '!=', $batch->id)
            ->with(['institution'])
            ->latest()
            ->get();

        return ImportBatchResource::collection($siblings);
    }

    /**
     * Sube el archivo y lo deja guardado en storage sin procesarlo todavía.
     * Devuelve la lista de hojas detectadas (una entrada virtual para CSV/TXT
     * o Excel de una sola hoja) para que el frontend arme el formulario de
     * asignación de fuente/institución por hoja antes de confirmar el envío.
     */
    public function preview(PreviewImportRequest $request, ImportParserService $parser): JsonResponse
    {
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        $storagePath = $file->store('imports/' . now()->format('Y/m'), 'local');

        $sheets = $parser->listSheets(Storage::path($storagePath), $extension);

        return response()->json([
            'storage_path'       => $storagePath,
            'original_filename'  => $file->getClientOriginalName(),
            'sheets'             => array_map(fn (?string $name) => ['sheet_name' => $name], $sheets),
        ]);
    }

    /**
     * Recibe la asignación de fuente/institución por hoja (del archivo ya
     * subido vía preview()) y crea UN ImportBatch por hoja, cada uno despachando
     * su propio job de procesamiento en background.
     *
     * La respuesta 202 indica que se aceptó la solicitud — no que ya terminó.
     */
    public function store(StoreImportRequest $request): JsonResponse
    {
        $storagePath = $request->input('storage_path');
        $originalFilename = $request->input('original_filename');

        $batches = collect($request->input('sheets'))->map(function (array $sheet) use ($storagePath, $originalFilename, $request) {
            $batch = ImportBatch::create([
                'source'            => $sheet['source'],
                'institution_id'    => $sheet['institution_id'] ?? null,
                'original_filename' => $originalFilename,
                'storage_path'      => $storagePath,
                'sheet_name'        => $sheet['sheet_name'] ?? null,
                'uploaded_by'       => $request->user()->id,
                'status'            => 'pending',
            ]);

            ProcessImportBatch::dispatch($batch->id, $storagePath, $sheet['sheet_name'] ?? null);

            return $batch->load(['institution', 'uploader']);
        });

        return ImportBatchResource::collection($batches)
            ->response()
            ->setStatusCode(202); // Accepted — procesamiento en curso
    }

    /**
     * Descarga la plantilla de columnas esperadas para una fuente, en el formato
     * elegido por el usuario (xlsx, csv o el TXT liviano delimitado por '|').
     *
     * Mismo permiso que store(): quien puede subir un archivo es quien necesita
     * la plantilla para prepararlo.
     */
    public function template(Request $request, ImportTemplateService $templates): BinaryFileResponse|StreamedResponse
    {
        abort_unless($request->user()->can('importaciones.gestionar'), 403, 'No tiene permiso para descargar plantillas de importación.');

        $data = $request->validate([
            'source' => ['required', 'in:civil_registry,education,health'],
            'format' => ['required', 'in:xlsx,csv,txt'],
        ]);

        $source = $data['source'];
        $format = $data['format'];
        $filename = $templates->filenameStem($source) . '.' . $format;

        if ($format === 'xlsx') {
            $dir = storage_path('app/private/exports');
            File::ensureDirectoryExists($dir);
            $path = $dir . DIRECTORY_SEPARATOR . $filename;

            (new Xlsx($templates->buildSpreadsheet($source)))->save($path);

            return response()->download($path, $filename)->deleteFileAfterSend(true);
        }

        $delimiter = $format === 'txt' ? '|' : ',';
        $content = $templates->buildDelimited($source, $delimiter);
        $mime = $format === 'txt' ? 'text/plain' : 'text/csv';

        return response()->streamDownload(
            fn () => print($content),
            $filename,
            ['Content-Type' => "{$mime}; charset=UTF-8"]
        );
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

        $query = $batch->rows()->with(['matchedRow', 'child', 'suggestedChild', 'batch']);

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
        $childId = $request->input('child_id');
        $dataSource = $request->input('data_source', 'row');
        // Correcciones tipeadas a mano por el operador antes de confirmar — solo
        // pisan los datos de ESTA fila, nunca los de la contraparte (matched_row).
        $overrides = array_filter(
            $request->input('overrides', []),
            fn ($value) => $value !== null,
        );

        // children.birth_date es NOT NULL: si la fila (o la contraparte elegida como
        // fuente de datos) no la trae, y no se está vinculando a un niño ya existente,
        // no hay forma de crear el registro. Se valida acá para devolver un mensaje
        // claro en vez de que explote el insert con un error de base de datos.
        if ($action !== 'skip' && ! $childId) {
            $sourceRow = ($dataSource === 'matched_row' && $row->matched_row_id)
                ? ImportRow::find($row->matched_row_id)
                : $row;
            $raw = $sourceRow ? (json_decode($sourceRow->raw_data, true) ?: []) : [];
            if ($dataSource !== 'matched_row') {
                $raw = array_merge($raw, $overrides);
            }
            if (empty($raw['birth_date'])) {
                return response()->json([
                    'message' => 'La fila de origen elegida no trae fecha de nacimiento, así que no se puede crear un niño nuevo. Vinculala a un niño existente, elegí la otra fuente de datos, completá la fecha editando la fila, o corregila en el archivo original.',
                ], 422);
            }
        }

        if ($action === 'skip') {
            $this->skipRow($row, $request->user()->id);
        } else {
            $this->confirmRow($row, $childId, $request->user()->id, $batch, $dataSource, $overrides);
        }

        $row->refresh()->load(['matchedRow', 'child', 'suggestedChild', 'batch']);

        return response()->json([
            'message' => $action === 'skip'
                ? 'Fila descartada correctamente.'
                : 'Fila vinculada correctamente.',
            'row'     => new ImportRowResource($row),
        ]);
    }

    /**
     * Crea un niño nuevo por cada fila 'no_match' del lote, de una sola vez.
     *
     * Pensado para cuando una hoja se sube sola (sin contraparte con la que
     * comparar en todo el sistema): cada fila termina en 'no_match' aunque el
     * dato esté perfecto, porque no hay nada contra qué emparejarla. Pedir
     * confirmación fila por fila en ese caso es puro trabajo manual sin
     * beneficio — acá se resuelven todas de un saque.
     *
     * A propósito NO toca 'partial_match': esas filas sí tienen una duda real
     * (tilde, DNI que no cierra del todo, candidato ambiguo) y requieren que
     * un humano decida — nunca se resuelven en lote.
     */
    public function bulkResolveNoMatch(Request $request, ImportBatch $batch): JsonResponse
    {
        abort_unless($request->user()->can('importaciones.gestionar'), 403, 'No tiene permiso para resolver importaciones.');

        $userId = $request->user()->id;
        $resolved = 0;
        $skipped = [];

        ImportRow::where('batch_id', $batch->id)
            ->where('status', 'no_match')
            ->orderBy('file_line_number')
            ->chunkById(100, function ($rows) use ($batch, $userId, &$resolved, &$skipped) {
                foreach ($rows as $row) {
                    $raw = json_decode($row->raw_data, true) ?: [];

                    if (empty($raw['birth_date'])) {
                        $skipped[] = $row->file_line_number;
                        continue;
                    }

                    $this->confirmRow($row, null, $userId, $batch);
                    $resolved++;
                }
            });

        return response()->json([
            'message'  => "{$resolved} fila(s) resuelta(s) creando un niño nuevo por cada una."
                . (count($skipped) > 0 ? ' ' . count($skipped) . ' fila(s) sin fecha de nacimiento quedaron pendientes (revisar manualmente).' : ''),
            'resolved' => $resolved,
            'skipped_lines' => $skipped,
        ]);
    }

    /**
     * Re-corre el matching de las filas sin resolver de un batch (partial_match/no_match)
     * contra una hoja puntual elegida a mano por el operador, en vez de la búsqueda
     * automática system-wide (cualquier batch de la fuente opuesta, alguna vez subido).
     *
     * Solo tiene sentido entre civil_registry↔education (la única fuente que hace
     * emparejamiento cruzado por nombre+fecha — ver ImportMatchingService::match()),
     * y solo contra una hoja del mismo archivo subido junto (mismo storage_path).
     *
     * Nunca auto-resuelve: aunque el resultado sea 100% de confianza, la fila queda
     * en 'partial_match' para que el operador confirme con el botón habitual.
     */
    public function rematchBatch(Request $request, ImportBatch $batch, ImportMatchingService $matcher): JsonResponse
    {
        abort_unless($request->user()->can('importaciones.gestionar'), 403, 'No tiene permiso para resolver importaciones.');

        $data = $request->validate([
            'against_batch_id' => ['required', 'uuid', 'exists:import_batches,id'],
        ]);

        $against = ImportBatch::findOrFail($data['against_batch_id']);

        if ($against->storage_path !== $batch->storage_path) {
            return response()->json([
                'message' => 'Solo se puede comparar contra otra hoja del mismo archivo subido.',
            ], 422);
        }

        $expectedOpposite = match ($batch->source) {
            'civil_registry' => 'education',
            'education'      => 'civil_registry',
            default          => null, // 'health' no hace emparejamiento cruzado por hoja
        };

        if ($expectedOpposite === null || $against->source !== $expectedOpposite) {
            return response()->json([
                'message' => 'Solo se puede comparar Registro Civil contra Educación (o viceversa). Salud no usa este tipo de comparación.',
            ], 422);
        }

        $updated = 0;

        ImportRow::where('batch_id', $batch->id)
            ->whereIn('status', ['partial_match', 'no_match'])
            ->orderBy('file_line_number')
            ->chunkById(100, function ($rows) use ($matcher, $against, &$updated) {
                foreach ($rows as $row) {
                    $result = $matcher->rematchAgainst($row, $against->id);
                    $matcher->applyMatch($row, $result);
                    $updated++;
                }
            });

        return response()->json([
            'message' => "{$updated} fila(s) recomparada(s) contra \"" . ($against->sheet_name ?? $against->original_filename) . '".',
            'updated' => $updated,
        ]);
    }

    /**
     * Reabre una fila ya resuelta ('matched' — dato de antes de que el sistema
     * pidiera aprobación siempre — o 'manual_resolved') para corregirla: vuelve
     * a quedar en 'partial_match'/'no_match', recalculando el matching desde
     * cero, así el operador confirma de nuevo con los botones habituales de la card.
     *
     * OJO: no borra el registro de dominio (BirthRecord/EducationRecord/HealthRecord)
     * que ya se había creado para el niño anterior — si el niño correcto termina
     * siendo otro, ese registro queda igual y hay que corregirlo a mano desde su
     * ficha. Se dice explícitamente en match_notes para que no se pierda de vista.
     */
    public function reopenRow(Request $request, ImportBatch $batch, ImportRow $row, ImportMatchingService $matcher): JsonResponse
    {
        abort_unless($request->user()->can('importaciones.gestionar'), 403, 'No tiene permiso para resolver importaciones.');

        if ($row->batch_id !== $batch->id) {
            abort(404);
        }

        if (! in_array($row->status, ['matched', 'manual_resolved'], true)) {
            return response()->json([
                'message' => 'Esta fila no está resuelta, no hace falta reabrirla.',
            ], 422);
        }

        DB::transaction(function () use ($row, $batch, $matcher, $request) {
            $previousChild = $row->child_id ? Child::find($row->child_id) : null;

            $reopenNote = $previousChild
                ? "Reabierta por {$request->user()->email} el " . now()->format('d/m/Y H:i')
                    . " — estaba vinculada a {$previousChild->first_name} {$previousChild->last_name}."
                    . ' El registro que ya se había creado para ese niño no se borró automáticamente:'
                    . ' si el niño correcto es otro, corregilo a mano desde su ficha.'
                : 'Reabierta por ' . $request->user()->email . ' el ' . now()->format('d/m/Y H:i') . '.';

            // OJO: matched_row_id NO se toca acá — se deja intacto para que, si la
            // rama de match() cruzado (más abajo) vuelve a encontrar contraparte,
            // applyMatch() pueda comparar contra el valor anterior y liberar la
            // vieja contraparte sola si ya no corresponde (su lógica ya existente
            // de "previousMatchedRowId"). Solo la rama de matchChild() lo limpia a
            // mano, porque esa rama nunca pasa por applyMatch().
            $row->update([
                'status'             => 'no_match',
                'child_id'           => null,
                'suggested_child_id' => null,
                'match_confidence'   => null,
                'resolved_by'        => null,
                'resolved_at'        => null,
                'match_notes'        => $reopenNote,
            ]);

            if (in_array($batch->source, ['civil_registry', 'education', 'health'], true)) {
                $childResult = $matcher->matchChild($row);

                if ($childResult->confidence > 0) {
                    $row->update([
                        'status'             => 'partial_match',
                        'match_confidence'   => $childResult->confidence,
                        'suggested_child_id' => $childResult->suggestedChildId,
                        'matched_row_id'     => null,
                        'match_notes'        => $reopenNote . ' | ' . $childResult->notes,
                    ]);
                    return;
                }
            }

            $result = $matcher->match($row);
            $matcher->applyMatch($row, $result);
            $row->update(['match_notes' => $reopenNote . ' | ' . $result->notes]);
        });

        $row->refresh()->load(['matchedRow', 'child', 'suggestedChild', 'batch']);

        return response()->json([
            'message' => 'Fila reabierta — quedó en revisión para que la vuelvas a confirmar.',
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

    /**
     * $dataSource: cuando NO se provee $childId y hay una contraparte (matched_row),
     * decide con cuál de las dos filas se identifica al niño NUEVO (nombre + fecha de
     * nacimiento) — 'row' (default) usa esta fila, 'matched_row' usa la contraparte.
     * No afecta los registros de dominio (BirthRecord/EducationRecord/HealthRecord):
     * cada uno siempre refleja los datos de SU propia fila, sea cual sea la elegida
     * como identidad del niño.
     *
     * $overrides: correcciones que el operador tipeó a mano antes de confirmar (ver
     * ResolveImportRowRequest) — pisan los datos de ESTA fila ($raw), nunca los de
     * la contraparte, así que solo tienen efecto real cuando $dataSource !== 'matched_row'.
     */
    private function confirmRow(ImportRow $row, ?string $childId, string $userId, ImportBatch $batch, string $dataSource = 'row', array $overrides = []): void
    {
        DB::transaction(function () use ($row, $childId, $userId, $batch, $dataSource, $overrides) {
            $raw = array_merge(json_decode($row->raw_data, true) ?: [], $overrides);

            $matchedRow = $row->matched_row_id ? ImportRow::find($row->matched_row_id) : null;
            $matchedRaw = $matchedRow ? (json_decode($matchedRow->raw_data, true) ?: []) : null;

            $childSourceRaw = ($dataSource === 'matched_row' && $matchedRaw) ? $matchedRaw : $raw;

            // Obtener o crear el niño
            $child = $childId
                ? Child::findOrFail($childId)
                : $this->createChildFromRow($childSourceRaw, $userId);

            // Crear el registro de dominio correspondiente a la fuente del batch
            match ($batch->source) {
                'civil_registry' => $this->createBirthRecordIfAbsent($child, $raw, $batch->institution_id),
                'health'         => $this->createHealthRecordIfAbsent($child, $raw, $batch->institution_id),
                default          => $this->createEducationRecordIfAbsent($child, $raw, $batch->institution_id),
            };

            // Actualizar esta fila
            $row->update([
                'status'      => 'manual_resolved',
                'child_id'    => $child->id,
                'resolved_by' => $userId,
                'resolved_at' => now(),
            ]);

            // Si hay una contraparte (partial_match), también vincularla
            if ($matchedRow && in_array($matchedRow->status, ['partial_match', 'no_match'])) {
                $matchedBatch = $matchedRow->batch;

                match ($matchedBatch->source) {
                    'civil_registry' => $this->createBirthRecordIfAbsent($child, $matchedRaw, $matchedBatch->institution_id),
                    'health'         => $this->createHealthRecordIfAbsent($child, $matchedRaw, $matchedBatch->institution_id),
                    default          => $this->createEducationRecordIfAbsent($child, $matchedRaw, $matchedBatch->institution_id),
                };

                $matchedRow->update([
                    'status'      => 'manual_resolved',
                    'child_id'    => $child->id,
                    'resolved_by' => $userId,
                    'resolved_at' => now(),
                ]);
            }
        });
    }

    // ─── Helpers de creación de registros ────────────────────────────────────────

    private function createChildFromRow(array $raw, string $createdBy): Child
    {
        $dni = $raw['dni'] ?? null;

        return Child::create([
            'first_name' => $raw['first_name'] ?? 'Sin nombre',
            'last_name'  => $raw['last_name'] ?? 'Sin apellido',
            'birth_date' => $raw['birth_date'] ?? null,
            'dni'        => $dni,
            'dni_hash'   => $dni ? ImportMatchingService::hashDni($dni) : null,
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

    /**
     * La institución ya se eligió al subir el archivo (es obligatoria para 'education'
     * en StoreImportRequest) — la fila NO necesita repetir el nombre de la escuela en
     * una columna para que el vínculo se cree. Si el archivo no trae 'school_name' (o
     * la hoja simplemente no tiene esa columna), se usa el nombre de la institución
     * como valor por defecto — mismo criterio que ya usa ChildController::store()
     * al auto-vincular un niño creado por una institución.
     */
    private function createEducationRecordIfAbsent(Child $child, array $raw, ?string $institutionId): void
    {
        EducationRecord::firstOrCreate(
            ['child_id' => $child->id],
            [
                'institution_id' => $institutionId,
                'school_name'    => $raw['school_name'] ?? Institution::find($institutionId)?->name ?? '',
                'grade_or_year'  => $raw['grade_or_year'] ?? null,
                'is_enrolled'    => true,
                'absences_count' => 0,
            ]
        );
    }

    /** Mismo criterio que createEducationRecordIfAbsent() — ver ese docblock. */
    private function createHealthRecordIfAbsent(Child $child, array $raw, ?string $institutionId): void
    {
        HealthRecord::firstOrCreate(
            ['child_id' => $child->id, 'institution_id' => $institutionId],
            [
                'health_center_name'      => $raw['health_center_name'] ?? Institution::find($institutionId)?->name ?? '',
                'healthy_checkup_current' => $raw['healthy_checkup_current'] ?? null,
                'vaccines_current'        => $raw['vaccines_current'] ?? null,
                'last_checkup_date'       => $raw['last_checkup_date'] ?? null,
                'observations'            => $raw['observations'] ?? null,
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