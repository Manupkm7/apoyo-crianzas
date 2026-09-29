<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prestaciones de un niño (salud, cuidado y educación, protección social,
 * recreación/deporte/cultura...), informadas por distintos efectores y por
 * período (trimestre o bimestre).
 *
 * - service_types: catálogo de prestaciones ("Control de niño sano", "AUH",
 *   "Fonoaudiología"...). Lo administra el admin; `is_mandatory` marca las que
 *   todo niño debería tener en cada período informado — si falta alguna, el SAT
 *   levanta la alerta 'prestacion_obligatoria_faltante' (ver ChildAlertEvaluator).
 *   Sin softDeletes: se desactiva con is_active (el nombre normalizado es único).
 *
 * - child_services: cada prestación recibida por un niño en un período, con su
 *   efector (SIEMPRE una institución del sistema), el sector que informó el
 *   archivo, observaciones y la marca de alerta que puede venir del archivo.
 *   Un mismo niño tiene muchas filas: una por prestación/efector/período.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name', 150);
            // minúsculas, sin tildes ni espacios repetidos — para matchear el
            // "Nombre prestación" del archivo sin importar cómo venga escrito.
            $table->string('name_normalized', 150)->unique();

            // Mismas claves que institutions.type: salud | educacion | desarrollo_social |
            // recreacion | otro (ver App\Support\Sector)
            $table->string('sector', 40);

            $table->text('description')->nullable();

            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('child_services', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('child_id');
            $table->uuid('service_type_id');
            // Efector: siempre una institución dada de alta en el sistema.
            $table->uuid('institution_id');

            // Sector tal como lo informó el archivo (o el del catálogo en la carga
            // manual). Puede no coincidir con el sector por defecto del catálogo.
            $table->string('sector', 40);

            // Período: año + tipo (trimestre | bimestre) + número.
            // period_start = primer día del período — sirve para ordenar y para
            // encontrar "el último período informado" sin importar el tipo.
            $table->unsignedSmallInteger('year');
            $table->string('period_type', 10);
            $table->unsignedTinyInteger('period_number');
            $table->date('period_start');

            // "Nro prestación" del archivo (numeración propia del organismo).
            $table->unsignedInteger('service_number')->nullable();

            // Cifrado (cast 'encrypted'): puede traer información sensible de la
            // familia (ej. "el hogar perdió el trabajo formal").
            $table->text('observations')->nullable();

            // Alerta informada por el efector (columna "Alerta" del archivo o carga
            // manual). alert_flagged_at = cuándo se marcó: el SAT la considera
            // pendiente hasta que alguien la gestiona DESPUÉS de esa fecha.
            $table->boolean('has_alert')->default(false);
            $table->timestamp('alert_flagged_at')->nullable();

            // Fila de importación que la originó (null = carga manual).
            $table->uuid('import_row_id')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('child_id')->references('id')->on('children')->cascadeOnDelete();
            $table->foreign('service_type_id')->references('id')->on('service_types')->restrictOnDelete();
            $table->foreign('institution_id')->references('id')->on('institutions')->restrictOnDelete();
            $table->foreign('import_row_id')->references('id')->on('import_rows')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Consulta caliente: prestaciones de un niño ordenadas por período.
            $table->index(['child_id', 'period_start']);
            $table->index(['institution_id']);

            $table->softDeletes();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE child_services ADD CONSTRAINT child_services_period_type_check CHECK (period_type IN ('trimestre', 'bimestre'))");
        DB::statement("ALTER TABLE child_services ADD CONSTRAINT child_services_period_number_check CHECK ((period_type = 'trimestre' AND period_number BETWEEN 1 AND 4) OR (period_type = 'bimestre' AND period_number BETWEEN 1 AND 6))");

        // Una misma prestación del mismo efector se informa una sola vez por
        // período. Índice parcial: las filas dadas de baja (soft delete) no
        // bloquean volver a cargarla.
        DB::statement('CREATE UNIQUE INDEX child_services_unique_per_period ON child_services (child_id, service_type_id, institution_id, year, period_type, period_number) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('child_services');
        Schema::dropIfExists('service_types');
    }
};
