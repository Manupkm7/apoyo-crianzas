<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega name_normalized/name_no_accents a children, mismo criterio que
 * import_rows (ver ImportMatchingService::normalizeName()/normalizeNameNoAccents()).
 *
 * Se usan para buscar candidatos a niño existente por nombre cuando el DNI de
 * la fila importada no está disponible o no coincide (ver
 * ImportMatchingService::matchChild()). El modelo Child los mantiene
 * actualizados en un hook 'saving'; los niños ya existentes se completan con
 * el comando 'children:backfill-normalized-names'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->string('name_normalized', 250)->nullable()->after('last_name');
            $table->string('name_no_accents', 250)->nullable()->after('name_normalized');
            $table->index('name_normalized');
            $table->index('name_no_accents');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropIndex(['name_normalized']);
            $table->dropIndex(['name_no_accents']);
            $table->dropColumn(['name_normalized', 'name_no_accents']);
        });
    }
};
