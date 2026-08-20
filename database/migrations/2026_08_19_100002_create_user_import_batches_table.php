<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de lotes de importación masiva de usuarios (personal institucional:
 * rol 'institucion' o 'representante' — ver `user_import_rows` para el detalle
 * de fila y `App\Services\Import\UserImportRowProcessor` para la lógica de
 * validación/creación).
 *
 * Un archivo corresponde siempre a UNA institución (igual criterio que
 * `import_batches.institution_id` para source=education): la institución
 * a la que pertenecerán los usuarios creados en este lote.
 *
 * El procesamiento es asíncrono (queue job), el estado evoluciona igual que
 * en `import_batches`: pending → processing → completed | failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('institution_id');
            $table->foreign('institution_id')->references('id')->on('institutions')->restrictOnDelete();

            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending');

            $table->string('original_filename', 255);

            // Contadores — se calculan al terminar el procesamiento
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_rows')->default(0);       // usuario creado automáticamente
            $table->unsignedInteger('needs_review_rows')->default(0);  // conflicto/dato inválido, espera revisión
            $table->unsignedInteger('skipped_rows')->default(0);       // descartada por el operador
            $table->unsignedInteger('error_rows')->default(0);         // error de parseo/procesamiento

            $table->uuid('uploaded_by')->nullable();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_import_batches');
    }
};
