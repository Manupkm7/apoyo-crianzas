<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca cuándo children.birth_date es una fecha genérica (1/1/2000) puesta por
 * el sistema porque el archivo importado no traía el dato, en vez de la fecha
 * real del niño — ver ImportController::GENERIC_BIRTH_DATE.
 *
 * Antes, a una fila de importación sin fecha de nacimiento se la bloqueaba con
 * un 422 (nunca se creaba el niño). Ahora se permite crear el niño igual, pero
 * hay que poder distinguir en el frontend qué niños tienen una fecha real de
 * cuáles tienen una genérica pendiente de corregir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->boolean('birth_date_is_placeholder')->default(false)->after('birth_date');
        });
    }

    public function down(): void
    {
        Schema::table('children', function (Blueprint $table) {
            $table->dropColumn('birth_date_is_placeholder');
        });
    }
};
