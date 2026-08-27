<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestión de alertas del Sistema de Alerta Temprana (SAT).
 *
 * Las alertas en sí NO se guardan: se calculan al vuelo a partir de la foto
 * vigente (education_records / health_records) y del último reporte bimestral
 * informado (ver App\Services\ChildAlertEvaluator).
 *
 * Esta tabla registra cada vez que la institución dueña del registro o el admin
 * marca una alerta como "gestionada / en seguimiento" (se coordinó un control
 * fuera de la plataforma). Mientras haya una fila vigente (expires_at > now)
 * para ese (niño, tipo de alerta), la alerta no cuenta como pendiente. Pasado
 * el plazo, si el problema sigue, la alerta vuelve a aparecer y se gestiona de
 * nuevo con una fila nueva — el histórico nunca se pisa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_acknowledgements', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('child_id');

            // no_escolarizado | inasistencias_elevadas | control_atrasado | vacunas_atrasadas
            $table->string('alert_type', 40);
            // educacion | salud — denormalizado para filtrar por sector sin recalcular
            $table->string('sector', 20);

            $table->text('note');

            // Quién gestionó. acknowledged_by es FK a users (null si el actor es la
            // propia Institution logueada). acknowledged_by_institution_id queda
            // seteado siempre que el actor sea institucional (User de institución o
            // Institution), null para el admin.
            $table->uuid('acknowledged_by')->nullable();
            $table->uuid('acknowledged_by_institution_id')->nullable();

            $table->timestamp('acknowledged_at');
            $table->timestamp('expires_at');

            // Foto de los valores que dispararon la alerta al momento de gestionarla
            // (ej. {"sources":["period"],"period":"2026-3","absences_count":14}).
            $table->jsonb('context')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('child_id')->references('id')->on('children')->cascadeOnDelete();
            $table->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('acknowledged_by_institution_id')->references('id')->on('institutions')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            // Consulta caliente: ¿hay gestión vigente para este niño + tipo?
            $table->index(['child_id', 'alert_type', 'expires_at']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_acknowledgements');
    }
};
