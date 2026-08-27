<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reportes por período (bimestre) del registro educativo y del de salud.
 *
 * El registro de dominio (education_records / health_records) sigue siendo la
 * FOTO vigente del niño en esa institución — es lo que alimenta las alertas del
 * SAT y lo que se muestra por defecto. Estos reportes son el HISTÓRICO
 * consultable: una fila por (registro, año, bimestre), nunca se pisan.
 *
 * Cuelgan del registro de dominio, no del niño: como un niño tiene un único
 * registro por dominio (unique child_id+institution_id), esto ya deja los
 * reportes acotados a "esta institución, este niño".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_period_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('education_record_id');

            $table->unsignedSmallInteger('year');      // ciclo lectivo, ej. 2026
            $table->unsignedTinyInteger('bimester');    // 1..6

            // Snapshot del nivel/grado en ese bimestre (puede cambiar entre bimestres
            // por promoción o repitencia). Nullable: se completa si se conoce.
            $table->string('level', 20)->nullable();
            $table->unsignedTinyInteger('grade')->nullable();

            $table->boolean('is_enrolled')->default(true);
            $table->unsignedSmallInteger('absences_count')->nullable(); // null = no informado
            $table->unsignedSmallInteger('present_days')->nullable();
            $table->unsignedSmallInteger('total_days')->nullable();

            $table->text('summary')->nullable(); // texto libre del bimestre

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('education_record_id')->references('id')->on('education_records')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Un único reporte por registro + año + bimestre
            $table->unique(['education_record_id', 'year', 'bimester']);
            $table->index(['education_record_id', 'year']);

            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('health_period_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('health_record_id');

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('bimester');    // 1..6

            // Mismo criterio que health_records: true = al día, false = atrasado,
            // null = sin dato (no se alarma sin evidencia).
            $table->boolean('healthy_checkup_current')->nullable();
            $table->boolean('vaccines_current')->nullable();
            $table->date('last_checkup_date')->nullable();

            // Seguimiento antropométrico del bimestre (opcional).
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();

            $table->text('summary')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('health_record_id')->references('id')->on('health_records')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['health_record_id', 'year', 'bimester']);
            $table->index(['health_record_id', 'year']);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_period_reports');
        Schema::dropIfExists('education_period_reports');
    }
};
