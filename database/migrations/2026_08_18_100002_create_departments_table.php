<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('province_id')->constrained('provinces')->restrictOnDelete();
            $table->string('external_code')->nullable();
            $table->string('name');
            $table->timestamps();

            $table->unique(['province_id', 'external_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
