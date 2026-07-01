<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de niños — registro base de cada menor en el sistema.
 *
 * Esta es la tabla central que comparten todos los módulos (salud, educación, etc.).
 * Cada institución agrega su propio registro específico en las tablas del dominio
 * (health_records, education_records), apuntando al niño por su ID.
 *
 * El DNI se almacena cifrado (AES-256 vía clave del servidor).
 * Un segundo campo, dni_hash, guarda el SHA-256 del DNI sin cifrar para poder
 * detectar duplicados sin exponer el dato sensible en texto plano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('children', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('first_name', 100);
            $table->string('last_name', 100);

            // Fecha de nacimiento para calcular edad y disparar alertas de control
            $table->date('birth_date');

            // DNI cifrado — solo legible por la aplicación con la clave del servidor
            $table->text('dni')->nullable();

            // Hash SHA-256 del DNI (sin cifrar) para detección de duplicados.
            // Permite buscar "¿ya existe este niño?" sin exponer el DNI real.
            $table->string('dni_hash', 64)->nullable()->unique();

            $table->text('notes')->nullable();

            // Registra quién creó y quién modificó el registro (para auditoría)
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->softDeletes(); // Los registros NUNCA se eliminan físicamente
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('children');
    }
};
