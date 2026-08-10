<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Registro educativo de un niño.
 *
 * Solo lo crean y modifican usuarios de instituciones de tipo 'educacion'.
 * Admin y coordinador pueden verlo pero no crearlo (a menos que el admin lo necesite).
 *
 * Un niño tiene UN único registro por institución educativa.
 * La combinación is_enrolled=false o absences_count elevado son señales para
 * el Sistema de Alerta Temprana (SAT) que se implementará más adelante.
 */
class EducationRecord extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'child_id',
        'institution_id',
        'school_name',
        'grade_or_year',
        'level',
        'grade',
        'absences_count',
        'attendance_present_days',
        'attendance_total_days',
        'attendance_period_label',
        'is_enrolled',
        'observations',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enrolled'              => 'boolean',
            'absences_count'           => 'integer',
            'grade'                    => 'integer',
            'attendance_present_days'  => 'integer',
            'attendance_total_days'    => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'school_name', 'grade_or_year', 'level', 'grade',
                'absences_count', 'attendance_present_days', 'attendance_total_days', 'attendance_period_label',
                'is_enrolled', 'observations',
            ])
            ->logOnlyDirty();
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function observationEntries(): HasMany
    {
        return $this->hasMany(EducationObservation::class)->latest();
    }

    /**
     * Etiqueta legible de un grado dentro de un nivel.
     * Ej: gradeLabel('primario', 4) → "4to grado"; gradeLabel('jardin', 3) → "Sala de 3".
     */
    public static function gradeLabel(string $level, int $grade): string
    {
        $ordinal = match ($grade) {
            1 => '1er',
            2 => '2do',
            3 => '3er',
            4 => '4to',
            5 => '5to',
            6 => '6to',
            7 => '7mo',
            default => "{$grade}º",
        };

        return match ($level) {
            'jardin'     => "Sala de {$grade}",
            'primario'   => "{$ordinal} grado",
            'secundario' => "{$ordinal} año",
            default      => (string) $grade,
        };
    }

    public static function levelLabel(string $level): string
    {
        return match ($level) {
            'jardin'     => 'Jardín',
            'primario'   => 'Primario',
            'secundario' => 'Secundario',
            default      => ucfirst($level),
        };
    }
}
