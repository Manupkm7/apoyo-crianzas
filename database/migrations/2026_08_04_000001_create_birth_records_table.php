<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de registros de nacimiento.
 *
 * Almacena el acto de nacimiento de un niño tal como lo carga la institución de salud.
 * Puede vincularse a un registro en `children` (child_id) si el niño ya existe en el sistema,
 * o quedar sin vincular y asociarse más adelante mediante búsqueda por DNI hash.
 *
 * Campos cifrados (AES-256 vía APP_KEY):
 *   - mother_name, mother_dni, father_name, father_dni, address
 *
 * Los campos *_hash son SHA-256 del valor sin cifrar y permiten detectar registros
 * duplicados o vincular familias sin exponer los datos reales.
 *
 * Los registros NUNCA se eliminan físicamente (softDeletes).
 * Ningún DNI aparece en el historial de auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birth_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Vínculo opcional con el registro base del niño en children
            $table->uuid('child_id')->nullable();

            // Nullable: en carga masiva (importación de Registro Civil) puede no haber
            // una institución del sistema vinculada. En carga manual siempre se provee.
            $table->uuid('institution_id')->nullable();

            // Datos del niño — nombre en texto plano (mismo criterio que children.first_name)
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date');

            // Datos de la madre (cifrados — PII de un adulto tercero)
            // Nullable: en importaciones masivas la columna puede no estar en el archivo
            $table->text('mother_name')->nullable();
            $table->text('mother_dni')->nullable();
            $table->string('mother_dni_hash', 64)->nullable()->index();

            // Datos del padre (opcionales — solo si está inscripto en el registro)
            $table->text('father_name')->nullable();
            $table->text('father_dni')->nullable();
            $table->string('father_dni_hash', 64)->nullable()->index();

            // Domicilio al momento del nacimiento (cifrado — dato personal sensible)
            $table->text('address')->nullable();

            // Establecimiento donde ocurrió el nacimiento (nombre del lugar, no sensible)
            $table->string('birth_establishment', 200)->nullable();

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
        Schema::dropIfExists('birth_records');
    }
};
