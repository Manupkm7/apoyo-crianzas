<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de filas individuales de cada lote de importación.
 *
 * Cada fila del archivo subido genera un ImportRow. El campo raw_data almacena
 * el contenido original cifrado (contiene DNIs, nombres, domicilios — todo PII).
 *
 * Para hacer el matching eficientemente sin descifrar raw_data en cada comparación,
 * se extraen dos versiones del nombre completo al momento de procesar:
 *
 *   name_normalized  — mb_strtolower(nombre + apellido), conserva tildes
 *                      → usado para el match exacto (Matías == matías)
 *
 *   name_no_accents  — igual pero sin diacríticos (Normalizer::FORM_D + strip Mn)
 *                      → si este coincide pero name_normalized no → conflicto de tilde
 *                      → se envía a revisión manual
 *
 * matched_row_id apunta a la contraparte de la otra fuente cuando hay match.
 * Ambas filas (civil + educación) se actualizan mutuamente en una transacción.
 *
 * El retroactive matching (cuando llega educación DESPUÉS de registro civil)
 * actualiza las filas de civil que estaban en no_match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('batch_id');
            $table->foreign('batch_id')->references('id')->on('import_batches')->cascadeOnDelete();

            $table->enum('status', [
                'pending',          // aún no procesada
                'matched',          // match automático (confianza 100), registro creado
                'partial_match',    // posible match con conflicto (tilde, ambigüedad), esperando revisión
                'no_match',         // no se encontró contraparte
                'manual_resolved',  // un operador resolvió manualmente
                'skipped',          // el operador decidió descartar la fila
                'error',            // error de parseo o procesamiento
            ])->default('pending');

            // Todo el contenido original de la fila, cifrado (contiene PII)
            $table->text('raw_data');

            // Nombre completo normalizado CON tildes, en minúsculas
            // Ej: "matías rodríguez" — para match exacto case-insensitive
            $table->string('name_normalized', 250)->nullable();
            $table->index('name_normalized');

            // Nombre completo sin diacríticos, en minúsculas
            // Ej: "matias rodriguez" — para detectar conflictos de tilde
            $table->string('name_no_accents', 250)->nullable();
            $table->index('name_no_accents');

            // Fecha de nacimiento en texto plano para poder indexar y comparar en SQL
            $table->date('birth_date')->nullable();
            $table->index('birth_date');

            // SHA-256 del DNI si la fila lo tiene (puede ser DNI del niño o de la madre)
            $table->string('dni_hash', 64)->nullable();
            $table->index('dni_hash');

            // Resultado del matching
            $table->tinyInteger('match_confidence')->nullable(); // 0-100
            $table->text('match_notes')->nullable();             // explicación del resultado

            // Si fue emparejada con una fila de la otra fuente
            // La foreign key autoreferenciada se agrega después de crear la tabla
            // (ver abajo): dentro del mismo Schema::create, Laravel encola el comando
            // de la primary key de "id" después de los foreign() explícitos, así que
            // en Postgres esta constraint fallaría por no encontrar aún esa PK.
            $table->uuid('matched_row_id')->nullable();

            // Registro creado o vinculado a partir de esta fila (después del match)
            $table->uuid('child_id')->nullable();
            $table->foreign('child_id')->references('id')->on('children')->nullOnDelete();

            // Auditoría de la resolución manual
            $table->uuid('resolved_by')->nullable();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            // Error específico de esta fila (parseo, datos inválidos, etc.)
            $table->text('error_message')->nullable();

            // Número de línea original en el archivo (para que el usuario pueda localizar la fila)
            $table->unsignedInteger('file_line_number')->nullable();

            $table->timestamps();
        });

        Schema::table('import_rows', function (Blueprint $table) {
            $table->foreign('matched_row_id')->references('id')->on('import_rows')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
    }
};
