<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            // Nullable: las instituciones ya existentes en producción no tienen contraseña
            // asignada todavía. Se completa al crear nuevas o al resetear las existentes.
            $table->string('password')->nullable();
            $table->boolean('password_must_change')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedTinyInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('institutions', function (Blueprint $table) {
            $table->dropColumn([
                'password', 'password_must_change', 'last_login_at',
                'last_login_ip', 'failed_login_attempts', 'locked_until',
            ]);
        });
    }
};
