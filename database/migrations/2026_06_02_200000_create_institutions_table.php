<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('type', [
                'salud',
                'educacion',
                'desarrollo_social',
                'justicia',
                'otro',
            ]);
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // FK from users to institutions (users table already exists)
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('institution_id')
                ->references('id')
                ->on('institutions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['institution_id']);
        });
        Schema::dropIfExists('institutions');
    }
};
