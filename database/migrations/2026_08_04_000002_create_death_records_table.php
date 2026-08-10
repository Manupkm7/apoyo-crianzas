<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de registros de defunción.
 *
 * Almacena el registro de fallecimiento de un niño. Puede vincularse a un registro
 * existente en `children` (child_id) o quedar sin vincular si el niño nunca fue
 * ingresado al sistema.
 *
 * El DNI de la madre (mother_dni) es el campo de vinculación principal: permite
 * asociar la defunción con otros registros de la misma familia sin depender de que
 * el niño tenga DNI propio (muy común en muertes neonatales).
 *
 * Campos cifrados (AES-256 vía APP_KEY):
 *   - child_dni, mother_dni, cause_of_death
 *
 * cause_of_death es el dato más sensible del sistema: se cifra, es nullable,
 * y se excluye COMPLETAMENTE del historial de auditoría (activity log).
 *
 * Los registros NUNCA se eliminan físicamente (softDeletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('death_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Vínculo opcional con el registro base del niño en children
            $table->uuid('child_id')->nullable();
            $table->uuid('institution_id'); // Institución que registró la defunción

            // Datos del niño
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date');
            $table->date('death_date');

            // DNI del niño (nullable — frecuente en muertes neonatales o sin documentación)
            $table->text('child_dni')->nullable();
            $table->string('child_dni_hash', 64)->nullable()->index();

            // DNI de la madre — campo de vinculación familiar (siempre presente si es posible)
            $table->text('mother_dni');
            $table->string('mother_dni_hash', 64)->index();

            // Causa de defunción — dato ALTAMENTE SENSIBLE.
            // Nullable: se puede registrar la defunción sin consignar la causa.
            // Se excluye del activity log en el modelo.
            $table->text('cause_of_death')->nullable();

            $table->text('observations')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();

            $table->foreign('child_id')->references('id')->on('children')->nullOnDelete();
            $table->foreign('institution_id')->references('id')->on('institutions')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('death_records');
    }
};
