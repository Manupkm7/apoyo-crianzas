<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha del efector. Un efector ES una institución: se amplía institutions en
 * vez de crear una entidad aparte.
 *
 *  - code: ID_EFECTOR, número correlativo legible generado por el sistema
 *    (el id sigue siendo UUID). Se completa para las ya existentes por orden de alta.
 *  - type: el "sector" (misma cosa). Se agrega 'recreacion' (Recreación, deporte
 *    y cultura). Las etiquetas cambian en App\Support\Sector, las claves no.
 *  - administrative_dependency: Estatal | Privado | Comunitario | Otros.
 *  - program_area_id: dependencia programática (área de gobierno), catálogo
 *    program_areas que crece desde el formulario.
 *  - beneficiaries: destinatarios (texto libre).
 *  - observations: texto largo.
 *  - institution_articulations: con qué otros efectores trabaja. Relación
 *    simétrica guardada UNA vez por par (institution_a_id < institution_b_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Sector: agrega 'recreacion' al CHECK de institutions.type ───────────
        $constraint = DB::selectOne(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE conrelid = 'institutions'::regclass
              AND contype = 'c'
              AND pg_get_constraintdef(oid) LIKE '%type%'
        SQL);

        if ($constraint) {
            DB::statement('ALTER TABLE institutions DROP CONSTRAINT ' . $constraint->conname);
        }

        DB::statement("ALTER TABLE institutions ADD CONSTRAINT institutions_type_check CHECK (type IN ('salud', 'educacion', 'desarrollo_social', 'recreacion', 'justicia', 'otro'))");

        // ── Catálogo de dependencias programáticas ──────────────────────────────
        Schema::create('program_areas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 200);
            $table->string('name_normalized', 200)->unique();
            $table->uuid('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        // ── Campos nuevos de la ficha ───────────────────────────────────────────
        Schema::table('institutions', function (Blueprint $table) {
            $table->string('administrative_dependency', 20)->nullable();
            $table->uuid('program_area_id')->nullable();
            $table->text('beneficiaries')->nullable();
            $table->text('observations')->nullable();

            $table->foreign('program_area_id')->references('id')->on('program_areas')->nullOnDelete();
        });

        DB::statement("ALTER TABLE institutions ADD CONSTRAINT institutions_administrative_dependency_check CHECK (administrative_dependency IS NULL OR administrative_dependency IN ('estatal', 'privado', 'comunitario', 'otros'))");

        // ── ID_EFECTOR correlativo ──────────────────────────────────────────────
        DB::statement('ALTER TABLE institutions ADD COLUMN code BIGINT');
        DB::statement('CREATE SEQUENCE institutions_code_seq OWNED BY institutions.code');
        DB::statement(<<<'SQL'
            UPDATE institutions i
            SET code = s.rn
            FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY created_at, name) AS rn FROM institutions) s
            WHERE i.id = s.id
        SQL);
        DB::statement("SELECT setval('institutions_code_seq', COALESCE((SELECT MAX(code) FROM institutions), 0) + 1, false)");
        DB::statement("ALTER TABLE institutions ALTER COLUMN code SET DEFAULT nextval('institutions_code_seq')");
        DB::statement('ALTER TABLE institutions ALTER COLUMN code SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX institutions_code_unique ON institutions (code)');

        // ── Articulaciones entre efectores ──────────────────────────────────────
        Schema::create('institution_articulations', function (Blueprint $table) {
            $table->uuid('institution_a_id');
            $table->uuid('institution_b_id');
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->primary(['institution_a_id', 'institution_b_id']);
            $table->index('institution_b_id');

            $table->foreign('institution_a_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign('institution_b_id')->references('id')->on('institutions')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        // Un par se guarda una sola vez y nunca consigo misma.
        DB::statement('ALTER TABLE institution_articulations ADD CONSTRAINT institution_articulations_order_check CHECK (institution_a_id < institution_b_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_articulations');

        DB::statement('DROP INDEX IF EXISTS institutions_code_unique');
        DB::statement('ALTER TABLE institutions DROP COLUMN IF EXISTS code');
        DB::statement('ALTER TABLE institutions DROP CONSTRAINT IF EXISTS institutions_administrative_dependency_check');

        Schema::table('institutions', function (Blueprint $table) {
            $table->dropForeign(['program_area_id']);
            $table->dropColumn(['administrative_dependency', 'program_area_id', 'beneficiaries', 'observations']);
        });

        Schema::dropIfExists('program_areas');

        DB::statement('ALTER TABLE institutions DROP CONSTRAINT IF EXISTS institutions_type_check');
        DB::statement("ALTER TABLE institutions ADD CONSTRAINT institutions_type_check CHECK (type IN ('salud', 'educacion', 'desarrollo_social', 'justicia', 'otro'))");
    }
};
