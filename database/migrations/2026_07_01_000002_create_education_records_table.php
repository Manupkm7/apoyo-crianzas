<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de registros educativos.
 *
 * Solo las instituciones de tipo 'educacion' pueden crear y modificar estos registros.
 * Cada niño puede tener un registro por institución educativa (constraint unique).
 *
 * El campo is_enrolled=false junto con absences_count elevado son indicadores
 * que el Sistema de Alerta Temprana (SAT) usará para generar alertas automáticas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('child_id');
            $table->uuid('institution_id'); // Institución educativa que cargó este registro

            $table->string('school_name', 200);
            $table->string('grade_or_year', 50)->nullable(); // Ej: "1er grado", "Sala de 4"

            // Cantidad de inasistencias en el ciclo lectivo actual
            $table->unsignedSmallInteger('absences_count')->default(0);

            // false = el niño no está actualmente escolarizado (alerta SAT)
            $table->boolean('is_enrolled')->default(true);

            $table->text('observations')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('child_id')->references('id')->on('children')->cascadeOnDelete();
            $table->foreign('institution_id')->references('id')->on('institutions')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Un niño tiene un único registro educativo por institución
            $table->unique(['child_id', 'institution_id']);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_records');
    }
};
