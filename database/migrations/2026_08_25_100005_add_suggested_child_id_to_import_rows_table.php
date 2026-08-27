<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * suggested_child_id: candidato a niño existente que propone
 * ImportMatchingService::matchChild() cuando DNI/nombre/apellido no coinciden
 * los 3 a la vez (confianza < 100). Distinto de child_id, que solo se completa
 * cuando la fila ya está resuelta (auto o manualmente) — es la sugerencia que
 * el operador puede aceptar o descartar en la revisión manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->uuid('suggested_child_id')->nullable()->after('child_id');
            $table->foreign('suggested_child_id')->references('id')->on('children')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_rows', function (Blueprint $table) {
            $table->dropForeign(['suggested_child_id']);
            $table->dropColumn('suggested_child_id');
        });
    }
};
