<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de observaciones de un registro de salud.
 *
 * A diferencia del campo 'observations' (texto libre, se sobrescribe),
 * cada HealthObservation es una entrada individual con autor y fecha, que
 * opcionalmente lleva un PDF adjunto (guardado en el disco 'local', nunca público).
 *
 * Solo la institución de salud dueña del registro puede agregar entradas.
 * Los representantes solo pueden ver, no escribir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('health_record_id');
            $table->uuid('author_id')->nullable();

            $table->text('body');

            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();

            $table->foreign('health_record_id')->references('id')->on('health_records')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_observations');
    }
};
