<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el DNI a los usuarios del sistema (necesario para la carga masiva de
 * usuarios institucionales: sirve como identificador de negocio y para
 * detectar duplicados antes de crear una cuenta nueva).
 *
 * Mismo criterio de seguridad que `birth_records`: el DNI se guarda cifrado
 * (`dni`, cast 'encrypted' en el modelo) y se agrega una columna paralela
 * `dni_hash` (SHA-256 determinístico) para poder buscar/detectar duplicados
 * sin descifrar — el cast 'encrypted' usa IV aleatorio, así que dos cifrados
 * del mismo DNI nunca son iguales byte a byte y un UNIQUE sobre `dni` no
 * serviría. A diferencia de `birth_records.*_dni_hash` (que no son únicos,
 * porque una madre puede tener varios hijos), acá sí es único: un DNI
 * corresponde a una sola cuenta de usuario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('dni')->nullable()->after('email');
            $table->string('dni_hash', 64)->nullable()->unique()->after('dni');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dni', 'dni_hash']);
        });
    }
};
