<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
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
        'absences_count',
        'is_enrolled',
        'observations',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_enrolled'    => 'boolean',
            'absences_count' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['school_name', 'grade_or_year', 'absences_count', 'is_enrolled', 'observations'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
