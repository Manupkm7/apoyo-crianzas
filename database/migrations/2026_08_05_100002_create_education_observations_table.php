<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Observaciones cargadas por la institución educativa sobre un niño.
 *
 * A diferencia del campo 'observations' (texto libre, un solo valor) de
 * education_records, esto es una bitácora: cada entrada queda con autor,
 * fecha y, opcionalmente, un PDF adjunto. Nunca se sobrescriben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('education_observations', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('education_record_id');
            $table->uuid('author_id')->nullable();

            $table->text('body');

            $table->string('attachment_path')->nullable();
            $table->string('attachment_original_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();

            $table->foreign('education_record_id')->references('id')->on('education_records')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('education_observations');
    }
};
