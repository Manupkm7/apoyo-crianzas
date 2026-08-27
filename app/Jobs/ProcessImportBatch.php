<?php

namespace App\Jobs;

use App\Models\BirthRecord;
use App\Models\Child;
use App\Models\EducationRecord;
use App\Models\HealthRecord;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Services\Import\ImportMatchingService;
use App\Services\Import\ImportParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job asíncrono que procesa un lote de importación.
 *
 * Flujo (ver processRow()):
 *   1. Parsea el archivo línea por línea (streaming, no carga todo en memoria)
 *   2. Por cada fila crea un ImportRow con raw_data cifrado y campos normalizados
 *   3. Las 3 fuentes primero intentan matchChild() (DNI+nombre+apellido) contra niños ya
 *      existentes; si las 3 señales no coinciden con un único candidato, la fila queda en
 *      'partial_match' con una sugerencia — nunca se vincula "a medias".
 *   4. Si matchChild() no encontró candidato:
 *      - 'civil_registry'/'education' siguen el matching cruzado de siempre entre sí, por
 *        nombre+fecha de nacimiento (confianza 100 → crea/vincula; <100 → 'partial_match';
 *        sin match → 'no_match', disponible para retroactive matching)
 *      - 'health' no tiene una fuente opuesta con la que emparejarse: si matchChild() no
 *        encontró candidato, queda directo en 'no_match' — NO crea un niño nuevo solo, para
 *        no duplicar cuando esa hoja se procesa antes que las otras (ver ImportMatchingService::match())
 *   5. Actualiza contadores del batch
 *
 * Retroactive matching: cuando educación llega DESPUÉS de registro civil ya importado,
 * las filas de civil en 'no_match' son actualizadas automáticamente por applyMatch().
 *
 * Timeout: 30 minutos (archivos grandes de miles de filas).
 * Reintentos: 1 (si falla el job completo se marca el batch como 'failed').
 */
class ProcessImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutos
    public int $tries   = 1;

    public function __construct(
        private readonly string $batchId,
        private readonly string $storagePath, // path relativo en storage/app/
        private readonly ?string $sheetName = null, // hoja del xlsx a procesar (null = activa / csv-txt)
    ) {}

    public function handle(ImportParserService $parser, ImportMatchingService $matcher): void
    {
        $batch = ImportBatch::findOrFail($this->batchId);
        $batch->markAsProcessing();

        try {
            // Reconstruir el archivo desde storage (fue guardado al recibir el upload)
            $absolutePath = Storage::path($this->storagePath);
            $file = new UploadedFile(
                $absolutePath,
                basename($this->storagePath),
                null,
                null,
                true // test mode: no valida que sea upload real
            );

            $totalRows = 0;

            foreach ($parser->parse($file, $batch->source, $this->sheetName) as $lineNumber => $rowData) {
                $totalRows++;

                try {
                    // Fila sin ningún dato identificatorio (nombre, apellido, ni DNI propio o
                    // de padres) — típicamente una fila "vacía" en el archivo original donde
                    // solo quedaron valores sueltos en columnas que no se mapean a nada útil.
                    // Se ignora en silencio: no cuenta como error ni ocupa la cola de revisión.
                    if ($this->isEffectivelyBlank($rowData)) {
                        $totalRows--;
                        continue;
                    }

                    if (empty($rowData['first_name']) || empty($rowData['last_name'])) {
                        // Hay ALGÚN dato (DNI de la fila o de los padres) pero falta nombre/apellido:
                        // esto sí es un problema real del archivo, no una fila vacía. Nunca se debe
                        // intentar matchear sin nombre: name_normalized quedaría null y Laravel
                        // convierte where('name_normalized', null) en whereNull(), lo que "matchea"
                        // contra CUALQUIER otra fila igual de incompleta.
                        $this->markRowError($batch->id, $lineNumber, $rowData, 'Falta nombre y/o apellido en esta fila. Revisar el mapeo de columnas del archivo.');
                        continue;
                    }

                    $importRow = $this->createImportRow($batch->id, $lineNumber, $rowData);
                    $this->processRow($batch, $matcher, $importRow, $rowData);
                } catch (\Throwable $e) {
                    $this->markRowError($batch->id, $lineNumber, $rowData, $e->getMessage());
                    Log::warning("ImportBatch {$this->batchId}: error en línea {$lineNumber}: {$e->getMessage()}");
                }
            }

            $batch->update(['total_rows' => $totalRows]);
            $batch->markAsCompleted();

            $this->deleteFileIfNoPendingBatches();

        } catch (\Throwable $e) {
            $batch->markAsFailed($e->getMessage());
            Log::error("ImportBatch {$this->batchId} falló: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            $this->deleteFileIfNoPendingBatches();
            throw $e;
        }
    }

    /**
     * Un mismo archivo subido puede haber generado varios batches (uno por hoja).
     * Todos comparten 'storage_path'. Solo se borra el archivo cuando ya no queda
     * ningún batch de ese archivo en 'pending'/'processing' (evita que un job
     * borre el archivo mientras otro todavía lo está leyendo).
     */
    private function deleteFileIfNoPendingBatches(): void
    {
        DB::transaction(function () {
            $stillPending = ImportBatch::where('storage_path', $this->storagePath)
                ->whereIn('status', ['pending', 'processing'])
                ->lockForUpdate()
                ->exists();

            if (! $stillPending) {
                Storage::delete($this->storagePath);
            }
        });
    }

    // ─── Creación del ImportRow ───────────────────────────────────────────────────

    private function createImportRow(string $batchId, int $lineNumber, array $rowData): ImportRow
    {
        $firstName = $rowData['first_name'] ?? '';
        $lastName  = $rowData['last_name'] ?? '';
        $birthDate = $rowData['birth_date'] ?? null;

        // Determinar qué DNI hashear: educación usa DNI del niño; civil usa DNI de la madre
        $dniForHash = $rowData['dni'] ?? $rowData['mother_dni'] ?? null;

        return ImportRow::create([
            'batch_id'          => $batchId,
            'status'            => 'pending',
            'raw_data'          => json_encode($this->rawForStorage($rowData), JSON_UNESCAPED_UNICODE),
            'name_normalized'   => $firstName && $lastName
                ? ImportMatchingService::normalizeName($firstName, $lastName)
                : null,
            'name_no_accents'   => $firstName && $lastName
                ? ImportMatchingService::normalizeNameNoAccents($firstName, $lastName)
                : null,
            'birth_date'        => $birthDate,
            'dni_hash'          => $dniForHash
                ? ImportMatchingService::hashDni($dniForHash)
                : null,
            'file_line_number'  => $lineNumber,
        ]);
    }

    /**
     * True si la fila no trae ningún dato identificatorio del niño ni de sus padres.
     *
     * ImportParserService::isBlankRow() solo descarta filas donde TODAS las columnas
     * del archivo original están vacías — no alcanza cuando el archivo tiene columnas
     * ajenas al matching (ej. "VAR1"..."VAR5") con contenido, porque esas alcanzan
     * para que la fila no se considere "en blanco" aunque nombre/apellido/DNI sí lo estén.
     */
    private function isEffectivelyBlank(array $rowData): bool
    {
        $identityFields = ['first_name', 'last_name', 'dni', 'mother_dni', 'father_dni'];

        foreach ($identityFields as $field) {
            if (! empty($rowData[$field])) {
                return false;
            }
        }

        return true;
    }

    // ─── Resolución de la fila ──────────────────────────────────────────────────────

    /**
     * Todas las fuentes pueden traer DNI propio del niño (algunas hojas de registro
     * civil también lo incluyen, no solo el de madre/padre): antes de cualquier otra
     * cosa, se intenta identificar a qué Child ya existente corresponde la fila
     * combinando DNI + nombre + apellido (matchChild). Si las 3 señales no coinciden
     * con un único candidato, la fila queda para revisión manual — nunca se vincula
     * "a medias".
     *
     * Si matchChild no encuentra ningún candidato (niño realmente nuevo, o la fila no
     * trae DNI/nombre reconocible), sigue el flujo existente: para 'civil_registry',
     * emparejamiento cruzado con 'education' por nombre+fecha de nacimiento.
     */
    private function processRow(ImportBatch $batch, ImportMatchingService $matcher, ImportRow $importRow, array $rowData): void
    {
        if (in_array($batch->source, ['civil_registry', 'education', 'health'], true)) {
            $childResult = $matcher->matchChild($importRow);

            if ($childResult->isAutomatic()) {
                $importRow->update([
                    'status'             => 'matched',
                    'match_confidence'   => 100,
                    'match_notes'        => $childResult->notes,
                    'suggested_child_id' => $childResult->childId,
                ]);
                $this->createRecords($batch, $importRow, $rowData, $childResult->childId);
                return;
            }

            if ($childResult->confidence > 0) {
                // DNI, nombre o apellido no coinciden los 3 a la vez con el mismo niño:
                // no se resuelve solo, un operador debe decidir a quién corresponde.
                $importRow->update([
                    'status'             => 'partial_match',
                    'match_confidence'   => $childResult->confidence,
                    'match_notes'        => $childResult->notes,
                    'suggested_child_id' => $childResult->suggestedChildId,
                ]);
                return;
            }

            // confidence === 0: ningún niño existente coincide → sigue el flujo normal
            // de abajo (crea uno nuevo; 'education' además intenta emparejar con civil_registry).
        }

        $result = $matcher->match($importRow);
        $matcher->applyMatch($importRow, $result);

        if ($result->isAutomatic()) {
            $this->createRecords($batch, $importRow, $rowData);
        }
    }

    // ─── Creación de registros de dominio (solo match automático) ─────────────────

    /**
     * Cuando el match es automático (confianza 100), crea o vincula los registros
     * de dominio correspondientes al origen del archivo.
     *
     * $knownChildId: cuando matchChild() ya identificó con certeza el Child (las 3
     * señales coinciden), se usa directo en vez de la búsqueda débil de
     * findOrCreateChild() (solo dni_hash exacto o nombre+fecha exactos).
     */
    private function createRecords(ImportBatch $batch, ImportRow $importRow, array $rowData, ?string $knownChildId = null): void
    {
        DB::transaction(function () use ($batch, $importRow, $rowData, $knownChildId) {
            $child = $knownChildId
                ? Child::findOrFail($knownChildId)
                : $this->findOrCreateChild($importRow, $rowData);

            match ($batch->source) {
                'civil_registry' => $this->createBirthRecord($child, $batch->institution_id, $rowData),
                'health'         => $this->createHealthRecord($child, $batch->institution_id, $rowData),
                default          => $this->createEducationRecord($child, $batch->institution_id, $rowData),
            };

            $importRow->update(['child_id' => $child->id]);

            // Vincular también la contraparte si existe
            if ($importRow->matched_row_id) {
                ImportRow::where('id', $importRow->matched_row_id)
                    ->update(['child_id' => $child->id]);
            }
        });
    }

    private function findOrCreateChild(ImportRow $importRow, array $rowData): Child
    {
        // Buscar por dni_hash primero (más fiable), luego por nombre+fecha
        if ($importRow->dni_hash) {
            $existing = Child::where('dni_hash', $importRow->dni_hash)->first();
            if ($existing) {
                return $existing;
            }
        }

        return Child::firstOrCreate(
            [
                // Buscar por nombre normalizado + fecha (sin exponer datos en plain text)
                'first_name' => $rowData['first_name'],
                'last_name'  => $rowData['last_name'],
                'birth_date' => $rowData['birth_date'] ?? null,
            ],
            ['created_by' => null] // creado por el sistema (importación automática)
        );
    }

    private function createBirthRecord(Child $child, ?string $institutionId, array $rowData): void
    {
        if ($child->birthRecord()->exists()) {
            return; // ya tiene registro de nacimiento, no duplicar
        }

        $motherDni = $rowData['mother_dni'] ?? null;
        $fatherDni = $rowData['father_dni'] ?? null;

        BirthRecord::create([
            'child_id'            => $child->id,
            'institution_id'      => $institutionId,
            'first_name'          => $rowData['first_name'],
            'last_name'           => $rowData['last_name'],
            'birth_date'          => $rowData['birth_date'] ?? null,
            'mother_name'         => $rowData['mother_name'] ?? null,
            'mother_dni'          => $motherDni,
            'mother_dni_hash'     => $motherDni ? ImportMatchingService::hashDni($motherDni) : null,
            'father_name'         => $rowData['father_name'] ?? null,
            'father_dni'          => $fatherDni,
            'father_dni_hash'     => $fatherDni ? ImportMatchingService::hashDni($fatherDni) : null,
            'address'             => $rowData['address'] ?? null,
            'birth_establishment' => $rowData['birth_establishment'] ?? null,
        ]);
    }

    private function createEducationRecord(Child $child, ?string $institutionId, array $rowData): void
    {
        if (! isset($rowData['school_name'])) {
            return;
        }

        EducationRecord::firstOrCreate(
            ['child_id' => $child->id],
            [
                'institution_id'  => $institutionId,
                'school_name'     => $rowData['school_name'] ?? '',
                'grade_or_year'   => $rowData['grade_or_year'] ?? null,
                'is_enrolled'     => true,
                'absences_count'  => 0,
            ]
        );
    }

    /**
     * A diferencia de educación, un niño SÍ puede tener un health_record por cada
     * institución de salud (health_records tiene unique(child_id, institution_id)),
     * así que la clave de firstOrCreate es compuesta.
     *
     * healthy_checkup_current/vaccines_current quedan null ("sin dato") cuando el
     * archivo no los trae — ver migración make_health_records_checkup_fields_nullable.
     */
    private function createHealthRecord(Child $child, ?string $institutionId, array $rowData): void
    {
        if (! isset($rowData['health_center_name'])) {
            return;
        }

        HealthRecord::firstOrCreate(
            ['child_id' => $child->id, 'institution_id' => $institutionId],
            [
                'health_center_name'      => $rowData['health_center_name'] ?? '',
                'healthy_checkup_current' => $rowData['healthy_checkup_current'] ?? null,
                'vaccines_current'        => $rowData['vaccines_current'] ?? null,
                'last_checkup_date'       => $rowData['last_checkup_date'] ?? null,
                'observations'            => $rowData['observations'] ?? null,
            ]
        );
    }

    // ─── Error en fila individual ─────────────────────────────────────────────────

    private function markRowError(string $batchId, int $lineNumber, array $rowData, string $message): void
    {
        ImportRow::create([
            'batch_id'          => $batchId,
            'status'            => 'error',
            'raw_data'          => json_encode($this->rawForStorage($rowData), JSON_UNESCAPED_UNICODE),
            'file_line_number'  => $lineNumber,
            'error_message'     => $message,
        ]);
    }

    /**
     * Guarda los campos ya MAPEADOS (first_name, last_name, dni, mother_name, etc.)
     * como raíz de raw_data — son los que lee ImportRowResource/ImportMatchingService
     * por nombre de campo — y preserva las columnas ORIGINALES del archivo (cualquiera
     * sea su nombre de cabecera) bajo '_original_columns', para que el operador pueda
     * ver el dato crudo aunque el archivo traiga columnas que no reconocemos.
     */
    private function rawForStorage(array $rowData): array
    {
        $originalColumns = $rowData['_raw'] ?? [];
        unset($rowData['_raw']);
        $rowData['_original_columns'] = $originalColumns;
        return $rowData;
    }
}
