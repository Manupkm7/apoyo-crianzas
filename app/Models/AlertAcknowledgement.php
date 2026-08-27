<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Una gestión de alerta: alguien (institución dueña del registro o admin) marcó
 * una alerta del SAT como "en seguimiento" porque se coordinó un control fuera
 * de la plataforma.
 *
 * Las filas nunca se pisan ni se borran: son el histórico de gestiones de ese
 * (niño, tipo de alerta). La gestión "vigente" es la más reciente con
 * expires_at en el futuro (ver App\Services\ChildAlertEvaluator).
 */
class AlertAcknowledgement extends Model
{
    use HasFactory, HasUuids, LogsActivity;

    protected $fillable = [
        'child_id',
        'alert_type',
        'sector',
        'note',
        'acknowledged_by',
        'acknowledged_by_institution_id',
        'acknowledged_at',
        'expires_at',
        'context',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
            'expires_at'      => 'datetime',
            'context'         => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['child_id', 'alert_type', 'sector', 'note', 'acknowledged_at', 'expires_at'])
            ->logOnlyDirty();
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function acknowledgedByInstitution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'acknowledged_by_institution_id');
    }

    /**
     * ¿Sigue vigente esta gestión (todavía no venció)?
     */
    public function isActive(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
