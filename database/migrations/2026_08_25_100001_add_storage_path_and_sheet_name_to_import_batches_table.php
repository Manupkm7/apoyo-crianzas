<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega 'storage_path' y 'sheet_name' a import_batches.
 *
 * Un mismo archivo subido puede generar varios batches (uno por hoja de Excel).
 * 'storage_path' es compartido entre todos los batches derivados de ese mismo
 * archivo — se usa para saber cuándo ya no queda ningún batch leyéndolo y
 * recién ahí borrarlo de storage (ver ProcessImportBatch).
 *
 * 'sheet_name' identifica de qué hoja vino el batch (null para CSV/TXT o
 * cuando el archivo no tenía más que una hoja).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('storage_path', 500)->nullable()->after('original_filename');
            $table->string('sheet_name', 120)->nullable()->after('storage_path');
            $table->index('storage_path');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex(['storage_path']);
            $table->dropColumn(['storage_path', 'sheet_name']);
        });
    }
};
