<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('localities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('external_code')->nullable();
            $table->string('name');
            $table->timestamps();

            $table->unique(['department_id', 'external_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('localities');
    }
};
