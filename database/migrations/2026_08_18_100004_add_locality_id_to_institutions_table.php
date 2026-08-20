<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            // Nullable: las instituciones existentes no tienen localidad cargada todavía
            // y se completan a mano hasta tener el backfill del catálogo geográfico.
            $table->foreignUuid('locality_id')->nullable()
                ->constrained('localities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locality_id');
        });
    }
};
