<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el campo 'health_profile' a la tabla health_records.
 *
 * Es un resumen narrativo del estado de salud actual del niño (ej: "Buen
 * desarrollo psicomotriz. Sin observaciones nutricionales."). A diferencia
 * de las observaciones de la bitácora (health_observations), este campo se
 * sobrescribe: siempre refleja el estado vigente, no un historial.
 *
 * Editable solo por la institución de salud responsable (no por representantes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->text('health_profile')->nullable()->after('observations');
        });
    }

    public function down(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->dropColumn('health_profile');
        });
    }
};
