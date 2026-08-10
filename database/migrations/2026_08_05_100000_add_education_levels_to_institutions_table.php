<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración de niveles educativos que ofrece una institución de tipo 'educacion'.
 *
 * Jardín maternal e infantil siempre va de sala 1 a sala 5 (edad del niño), por lo
 * que no necesita un campo de "cantidad de años" propio. Primario y secundario sí
 * varían según la institución (6 o 7 años), de ahí los campos *_years.
 *
 * Estos campos no tienen efecto en instituciones que no son de tipo 'educacion'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->boolean('offers_jardin')->default(false)->after('phone');
            $table->boolean('offers_primario')->default(false)->after('offers_jardin');
            $table->unsignedTinyInteger('primario_years')->nullable()->after('offers_primario');
            $table->boolean('offers_secundario')->default(false)->after('primario_years');
            $table->unsignedTinyInteger('secundario_years')->nullable()->after('offers_secundario');
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'offers_jardin',
                'offers_primario',
                'primario_years',
                'offers_secundario',
                'secundario_years',
            ]);
        });
    }
};
