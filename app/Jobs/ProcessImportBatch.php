<?php

namespace App\Jobs;

use App\Models\BirthRecord;
use App\Models\Child;
use App\Models\EducationRecord;
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
 * Flujo:
 *   1. Parsea el archivo línea por línea (streaming, no carga todo en memoria)
 *   2. Por cada fila crea un ImportRow con raw_data cifrado y campos normalizados
 *   3. Ejecuta el matching contra la fuente opuesta
 *   4. Aplica el resultado:
 *      - confianza 100 → crea/vincula registros en children + birth/education_records
 *      - confianza < 100 → deja en 'partial_match' para revisión del operador
 *      - sin match → deja en 'no_match', quedará disponible para retroactive matching
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

            foreach ($parser->parse($file, $batch->source) as $lineNumber => $rowData) {
                $totalRows++;

                try {
                    $importRow = $this->createImportRow($batch->id, $lineNumber, $rowData);
                    $result = $matcher->match($importRow);
                    $matcher->applyMatch($importRow, $result);

                    if ($result->isAutomatic()) {
                        $this->createRecords($batch, $importRow, $rowData);
                    }
                } catch (\Throwable $e) {
                    $this->markRowError($batch->id, $lineNumber, $rowData, $e->getMessage());
                    Log::warning("ImportBatch {$this->batchId}: error en línea {$lineNumber}: {$e->getMessage()}");
                }
            }

            $batch->update(['total_rows' => $totalRows]);
            $batch->markAsCompleted();

            // Eliminar el archivo después de procesar (contiene PII)
            Storage::delete($this->storagePath);

        } catch (\Throwable $e) {
            $batch->markAsFailed($e->getMessage());
            Log::error("ImportBatch {$this->batchId} falló: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    // ─── Creación del ImportRow ───────────────────────────────────────────────────

    private function createImportRow(string $batchId, int $lineNumber, array $rowData): ImportRow
    {
        $firstName = $rowData['first_name'] ?? '';
        $lastName  = $rowData['last_name'] ?? '';
        $birthDate = $rowData['birth_date'] ?? null;

        // Determinar qué DNI hashear: educación usa DNI del niño; civil usa DNI de la madre
        $dniForHash = $rowData['dni'] ?? $rowData['mother_dni'] ?? null;

        $rawForStorage = $rowData['_raw'] ?? $rowData;
        unset($rawForStorage['_raw']);

        return ImportRow::create([
            'batch_id'          => $batchId,
            'status'            => 'pending',
            'raw_data'          => json_encode($rawForStorage, JSON_UNESCAPED_UNICODE),
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

    // ─── Creación de registros de dominio (solo match automático) ─────────────────

    /**
     * Cuando el match es automático (confianza 100), crea o vincula los registros
     * de dominio correspondientes al origen del archivo.
     *
     * La estrategia es find-or-create en children por name_normalized + birth_date
     * para evitar duplicados si el mismo niño aparece en múltiples importaciones.
     */
    private function createRecords(ImportBatch $batch, ImportRow $importRow, array $rowData): void
    {
        DB::transaction(function () use ($batch, $importRow, $rowData) {
            $child = $this->findOrCreateChild($importRow, $rowData);

            if ($batch->source === 'civil_registry') {
                $this->createBirthRecord($child, $batch->institution_id, $rowData);
            } else {
                $this->createEducationRecord($child, $batch->institution_id, $rowData);
            }

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
                'birth_date' => $rowData['birth_date'],
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
            'birth_date'          => $rowData['birth_date'],
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

    // ─── Error en fila individual ─────────────────────────────────────────────────

    private function markRowError(string $batchId, int $lineNumber, array $rowData, string $message): void
    {
        $rawForStorage = $rowData['_raw'] ?? $rowData;
        unset($rawForStorage['_raw']);

        ImportRow::create([
            'batch_id'          => $batchId,
            'status'            => 'error',
            'raw_data'          => json_encode($rawForStorage, JSON_UNESCAPED_UNICODE),
            'file_line_number'  => $lineNumber,
            'error_message'     => $message,
        ]);
    }
}
