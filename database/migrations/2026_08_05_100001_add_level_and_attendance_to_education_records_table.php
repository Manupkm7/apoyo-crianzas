<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega nivel/grado estructurado y asistencia al registro educativo.
 *
 * 'grade_or_year' (texto libre) se conserva para no perder datos históricos,
 * pero de acá en adelante el nivel/grado se cargan de forma estructurada
 * (level + grade) para poder agrupar y contar por grado en el dashboard
 * de instituciones educativas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_records', function (Blueprint $table) {
            $table->string('level', 20)->nullable()->after('grade_or_year'); // jardin | primario | secundario
            $table->unsignedTinyInteger('grade')->nullable()->after('level'); // 1..7 dentro del nivel

            $table->unsignedSmallInteger('attendance_present_days')->nullable()->after('absences_count');
            $table->unsignedSmallInteger('attendance_total_days')->nullable()->after('attendance_present_days');
            $table->string('attendance_period_label', 100)->nullable()->after('attendance_total_days');
        });
    }

    public function down(): void
    {
        Schema::table('education_records', function (Blueprint $table) {
            $table->dropColumn([
                'level',
                'grade',
                'attendance_present_days',
                'attendance_total_days',
                'attendance_period_label',
            ]);
        });
    }
};
