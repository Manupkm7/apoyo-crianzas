<?php

namespace App\Jobs;

use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use App\Services\Import\ImportParserService;
use App\Services\Import\RowProcessResult;
use App\Services\Import\UserImportRowProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job asíncrono que procesa un lote de importación de usuarios.
 *
 * Flujo por fila (delegado a UserImportRowProcessor, la misma lógica que usa
 * la resolución manual de una fila en revisión):
 *   1. Validar nombre/apellido/DNI (8 dígitos)/rol
 *   2. Detectar duplicados (contra `users` y contra el resto del archivo)
 *   3. Si no hay conflictos: crear el usuario (email ficticio, inactivo)
 *   4. Si hay conflicto: la fila queda 'needs_review' con el motivo
 *
 * Timeout: 30 minutos. Reintentos: 1 (si falla el job completo se marca el
 * batch como 'failed').
 */
class ProcessUserImportBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutos
    public int $tries   = 1;

    public function __construct(
        private readonly string $batchId,
        private readonly string $storagePath, // path relativo en storage/app/
        private readonly array $rolesAllowedForUploader, // subconjunto de ['institucion', 'representante']
    ) {}

    public function handle(ImportParserService $parser, UserImportRowProcessor $processor): void
    {
        $batch = UserImportBatch::findOrFail($this->batchId);
        $batch->markAsProcessing();

        try {
            $absolutePath = Storage::path($this->storagePath);
            $file = new UploadedFile(
                $absolutePath,
                basename($this->storagePath),
                null,
                null,
                true // test mode: no valida que sea upload real
            );

            $totalRows = 0;

            foreach ($parser->parse($file, 'users') as $lineNumber => $rowData) {
                $totalRows++;

                try {
                    $result = $processor->process(
                        $rowData,
                        $batch->institution_id,
                        $batch->uploaded_by,
                        $this->rolesAllowedForUploader,
                    );

                    $this->storeRow($batch->id, $lineNumber, $rowData, $result);
                } catch (\Throwable $e) {
                    $this->markRowError($batch->id, $lineNumber, $rowData, $e->getMessage());
                    Log::warning("UserImportBatch {$this->batchId}: error en línea {$lineNumber}: {$e->getMessage()}");
                }
            }

            $batch->update(['total_rows' => $totalRows]);
            $batch->markAsCompleted();

            // Eliminar el archivo después de procesar (contiene DNIs)
            Storage::delete($this->storagePath);

        } catch (\Throwable $e) {
            $batch->markAsFailed($e->getMessage());
            Log::error("UserImportBatch {$this->batchId} falló: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    private function storeRow(string $batchId, int $lineNumber, array $rowData, RowProcessResult $result): void
    {
        UserImportRow::create([
            'batch_id'          => $batchId,
            'status'            => $result->toStatus(),
            'raw_data'          => json_encode($this->rawForStorage($rowData), JSON_UNESCAPED_UNICODE),
            'dni_hash'          => $result->dniHash,
            'role'              => $result->role,
            'review_reason'     => $result->reviewReason,
            'notes'             => $result->notes,
            'created_user_id'   => $result->userId,
            'file_line_number'  => $lineNumber,
        ]);
    }

    private function markRowError(string $batchId, int $lineNumber, array $rowData, string $message): void
    {
        UserImportRow::create([
            'batch_id'          => $batchId,
            'status'            => 'error',
            'raw_data'          => json_encode($this->rawForStorage($rowData), JSON_UNESCAPED_UNICODE),
            'file_line_number'  => $lineNumber,
            'error_message'     => $message,
        ]);
    }

    /**
     * A diferencia de ProcessImportBatch (que persiste raw_data con los headers
     * ORIGINALES del archivo — "Nombre", "ID_NOMBRE", etc., lo que sea que haya
     * traído esa fila), acá persistimos los campos ya MAPEADOS (first_name,
     * last_name, dni, role). Así UserImportRowResource puede mostrarlos de forma
     * confiable sin importar qué variante de columna trajo el archivo original.
     */
    private function rawForStorage(array $rowData): array
    {
        return [
            'first_name' => $rowData['first_name'] ?? null,
            'last_name'  => $rowData['last_name'] ?? null,
            'dni'        => $rowData['dni'] ?? null,
            'role'       => $rowData['role'] ?? null,
        ];
    }
}
