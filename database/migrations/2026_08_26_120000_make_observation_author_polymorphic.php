<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El autor de una observación (educativa o de salud) puede ser un User o una
 * Institution (login institucional — solo la institución de salud/educación
 * dueña del registro puede cargar entradas). author_id era uuid + FK a users;
 * pasa a una relación polimórfica (author_type + author_id), igual que el
 * causer de activity_log. Se quita la FK a users y se marcan las filas
 * existentes como User.
 */
return new class extends Migration
{
    private array $tables = ['education_observations', 'health_observations'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['author_id']);
                $t->string('author_type')->nullable()->after('author_id');
                $t->index(['author_type', 'author_id']);
            });

            // Todo autor cargado hasta ahora era un User (era el único actor posible).
            DB::table($table)
                ->whereNotNull('author_id')
                ->update(['author_type' => \App\Models\User::class]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            // Las filas cuyo autor sea una Institution quedarían huérfanas al
            // reponer la FK a users: se les borra el autor.
            DB::table($table)
                ->where('author_type', '!=', \App\Models\User::class)
                ->update(['author_id' => null]);

            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['author_type', 'author_id']);
                $t->dropColumn('author_type');
                $t->foreign('author_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }
};
