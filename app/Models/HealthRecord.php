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
 * Registro de salud de un niño.
 *
 * Solo lo crean y modifican usuarios de instituciones de tipo 'salud'.
 * Admin y coordinador pueden verlo.
 *
 * Un niño tiene UN único registro por institución de salud.
 * healthy_checkup_current=false o vaccines_current=false son señales para
 * el Sistema de Alerta Temprana (SAT) que se implementará más adelante.
 */
class HealthRecord extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'child_id',
        'institution_id',
        'health_center_name',
        'healthy_checkup_current',
        'vaccines_current',
        'last_checkup_date',
        'observations',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'healthy_checkup_current' => 'boolean',
            'vaccines_current'        => 'boolean',
            'last_checkup_date'       => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['health_center_name', 'healthy_checkup_current', 'vaccines_current', 'last_checkup_date', 'observations'])
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
