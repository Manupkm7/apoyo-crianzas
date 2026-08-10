<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de lotes de importación.
 *
 * Cada vez que se sube un archivo (registro civil o educación) se crea un ImportBatch.
 * El procesamiento ocurre de forma asíncrona (queue job), por lo que el estado
 * evoluciona: pending → processing → completed | failed.
 *
 * Los contadores (matched_rows, partial_rows, etc.) se actualizan al finalizar
 * el job para dar visibilidad sobre los resultados sin tener que contar filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Fuente del archivo: registro civil o educación
            $table->enum('source', ['civil_registry', 'education']);

            // Institución del sistema a la que pertenece la importación.
            // Requerido para 'education' (es una escuela del sistema).
            // Nullable para 'civil_registry' (entidad externa).
            $table->uuid('institution_id')->nullable();
            $table->foreign('institution_id')->references('id')->on('institutions')->nullOnDelete();

            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending');

            // Nombre original del archivo subido (para mostrar al usuario)
            $table->string('original_filename', 255);

            // Contadores — se calculan al terminar el procesamiento
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);      // confianza 100, creados automáticamente
            $table->unsignedInteger('partial_rows')->default(0);      // confianza < 100, esperando revisión
            $table->unsignedInteger('no_match_rows')->default(0);     // sin contraparte encontrada
            $table->unsignedInteger('error_rows')->default(0);        // filas con error de parseo

            // Quién subió el archivo
            $table->uuid('uploaded_by')->nullable();
            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            // Tiempos del procesamiento
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // En caso de fallo: mensaje de error general del job
            $table->text('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
