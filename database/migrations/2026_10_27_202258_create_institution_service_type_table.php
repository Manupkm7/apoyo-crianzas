<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_service_type', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('institution_id')
                ->constrained('institutions')
                ->cascadeOnDelete();

            $table->foreignUuid('service_type_id')
                ->constrained('service_types')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(
                ['institution_id', 'service_type_id'],
                'institution_service_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_service_type');
    }
};