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
 * Reporte bimestral del registro de salud de un niño.
 *
 * Histórico consultable: una fila por (health_record, año, bimestre). No se
 * pisa — a diferencia de HealthRecord, que es la foto vigente.
 */
class HealthPeriodReport extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'health_record_id',
        'year',
        'bimester',
        'healthy_checkup_current',
        'vaccines_current',
        'last_checkup_date',
        'weight_kg',
        'height_cm',
        'summary',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'year'                    => 'integer',
            'bimester'                => 'integer',
            'healthy_checkup_current' => 'boolean',
            'vaccines_current'        => 'boolean',
            'last_checkup_date'       => 'date',
            'weight_kg'               => 'decimal:2',
            'height_cm'               => 'decimal:2',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'year', 'bimester', 'healthy_checkup_current', 'vaccines_current',
                'last_checkup_date', 'weight_kg', 'height_cm', 'summary',
            ])
            ->logOnlyDirty();
    }

    public function healthRecord(): BelongsTo
    {
        return $this->belongsTo(HealthRecord::class);
    }
}
