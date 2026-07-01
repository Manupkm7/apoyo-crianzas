<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de registros de salud.
 *
 * Solo las instituciones de tipo 'salud' pueden crear y modificar estos registros.
 * Cada niño puede tener un registro por institución de salud (constraint unique).
 *
 * healthy_checkup_current=false o vaccines_current=false son indicadores que el
 * Sistema de Alerta Temprana (SAT) usará para generar alertas automáticas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('child_id');
            $table->uuid('institution_id'); // Salita u hospital que cargó este registro

            // Centro de salud al que asiste el niño (salita, hospital, CAPS, etc.)
            $table->string('health_center_name', 200);

            // false = no tiene el control de niño sano al día (alerta SAT)
            $table->boolean('healthy_checkup_current');

            // false = vacunas no están al día (alerta SAT)
            $table->boolean('vaccines_current');

            // Fecha del último control — para detectar ausencia prolongada de controles
            $table->date('last_checkup_date')->nullable();

            $table->text('observations')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('child_id')->references('id')->on('children')->cascadeOnDelete();
            $table->foreign('institution_id')->references('id')->on('institutions')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Un niño tiene un único registro de salud por institución
            $table->unique(['child_id', 'institution_id']);

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_records');
    }
};
