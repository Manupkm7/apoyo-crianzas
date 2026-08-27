<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Reporte bimestral del registro educativo de un niño.
 *
 * Histórico consultable: una fila por (education_record, año, bimestre). No se
 * pisa — a diferencia de EducationRecord, que es la foto vigente.
 */
class EducationPeriodReport extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'education_record_id',
        'year',
        'bimester',
        'level',
        'grade',
        'is_enrolled',
        'absences_count',
        'present_days',
        'total_days',
        'summary',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'year'           => 'integer',
            'bimester'       => 'integer',
            'grade'          => 'integer',
            'is_enrolled'    => 'boolean',
            'absences_count' => 'integer',
            'present_days'   => 'integer',
            'total_days'     => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'year', 'bimester', 'level', 'grade', 'is_enrolled',
                'absences_count', 'present_days', 'total_days', 'summary',
            ])
            ->logOnlyDirty();
    }

    public function educationRecord(): BelongsTo
    {
        return $this->belongsTo(EducationRecord::class);
    }
}
