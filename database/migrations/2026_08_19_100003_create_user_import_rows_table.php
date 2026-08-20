<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de filas individuales de cada lote de importación de usuarios.
 *
 * Cada fila del archivo (ID_NOMBRE, ID_APELLIDO, ID_DNI, ROL) genera un
 * user_import_row. El contenido original se guarda cifrado en raw_data
 * (contiene DNI — PII), igual criterio que `import_rows`.
 *
 * dni_hash permite detectar duplicados (contra `users` existentes y contra
 * otras filas del mismo lote) sin descifrar raw_data en cada comparación.
 *
 * Estados:
 *   pending        — aún no procesada
 *   created        — usuario creado automáticamente (sin conflictos)
 *   needs_review   — conflicto o dato inválido, esperando revisión manual
 *                     (ver review_reason: duplicate_dni_existing,
 *                     duplicate_dni_in_file, invalid_dni, missing_name,
 *                     invalid_role, institution_head_conflict)
 *   skipped        — el operador decidió descartar la fila
 *   error          — error de parseo o procesamiento inesperado
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('batch_id');
            $table->foreign('batch_id')->references('id')->on('user_import_batches')->cascadeOnDelete();

            $table->enum('status', ['pending', 'created', 'needs_review', 'skipped', 'error'])
                ->default('pending');

            // Todo el contenido original de la fila, cifrado (contiene el DNI)
            $table->text('raw_data');

            // SHA-256 del DNI de la fila, para detectar duplicados sin descifrar
            $table->string('dni_hash', 64)->nullable()->index();

            // Rol ya normalizado a partir de la columna ROL del archivo
            $table->string('role', 20)->nullable();

            $table->string('review_reason', 40)->nullable();
            $table->text('notes')->nullable();

            // Usuario creado a partir de esta fila (cuando status = created)
            $table->uuid('created_user_id')->nullable();
            $table->foreign('created_user_id')->references('id')->on('users')->nullOnDelete();

            // Auditoría de la resolución manual
            $table->uuid('resolved_by')->nullable();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->text('error_message')->nullable();

            // Número de línea original en el archivo (para que el usuario pueda localizar la fila)
            $table->unsignedInteger('file_line_number')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_import_rows');
    }
};
