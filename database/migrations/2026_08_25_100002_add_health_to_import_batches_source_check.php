<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega 'health' a los valores válidos de import_batches.source.
 *
 * $table->enum() en Postgres se compila como varchar + CHECK constraint (no
 * un tipo enum nativo), y Laravel no lo nombra explícito — Postgres lo genera
 * por convención ("<tabla>_<columna>_check"), pero se resuelve el nombre real
 * consultando el catálogo en vez de asumirlo, por si la convención cambiara.
 */
return new class extends Migration
{
    public function up(): void
    {
        $constraint = DB::selectOne(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE conrelid = 'import_batches'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%source%'
        SQL);

        if ($constraint) {
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT ' . $constraint->conname);
        }

        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_source_check CHECK (source IN ('civil_registry', 'education', 'health'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batches_source_check');
        DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batches_source_check CHECK (source IN ('civil_registry', 'education'))");
    }
};
