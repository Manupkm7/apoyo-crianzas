<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * healthy_checkup_current / vaccines_current pasan a nullable.
 *
 * Estos campos siguen siendo obligatorios cuando una institución de salud
 * carga o edita el registro a mano (ver StoreHealthRecordRequest, sin cambios).
 * Pero un registro creado automáticamente por importación masiva puede no
 * traer este dato en el archivo — en ese caso debe guardarse como "sin dato"
 * (null), NO como false, porque false dispara alertas del SAT (Sistema de
 * Alerta Temprana) sobre una situación que en realidad es desconocida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->boolean('healthy_checkup_current')->nullable()->default(null)->change();
            $table->boolean('vaccines_current')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('health_records', function (Blueprint $table) {
            $table->boolean('healthy_checkup_current')->nullable(false)->change();
            $table->boolean('vaccines_current')->nullable(false)->change();
        });
    }
};
