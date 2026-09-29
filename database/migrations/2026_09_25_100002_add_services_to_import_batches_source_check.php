<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega 'services' (prestaciones por período) a los valores válidos de
 * import_batches.source. Mismo mecanismo que
 * 2026_08_25_100002_add_health_to_import_batches_source_check.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT IF EXISTS import_batches_source_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_source_check CHECK (source IN ('civil_registry', 'education', 'health', 'services'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT IF EXISTS import_batches_source_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_source_check CHECK (source IN ('civil_registry', 'education', 'health'))");
    }
};
