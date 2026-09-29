<?php

namespace App\Models;

use App\Support\ServicePeriod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

/**
 * Una prestación recibida por un niño en un período (trimestre o bimestre),
 * informada por un efector (institución del sistema).
 *
 * El mismo niño tiene muchas: una por prestación + efector + período (índice
 * único parcial child_services_unique_per_period).
 *
 * has_alert = el efector marcó una alerta (columna "Alerta" del archivo o carga
 * manual). El SAT la considera pendiente hasta que alguien la gestiona después
 * de alert_flagged_at — ver ChildAlertEvaluator::TYPE_ALERTA_PRESTACION.
 */
class ChildService extends Model
{
    use HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'child_id',
        'service_type_id',
        'institution_id',
        'sector',
        'year',
        'period_type',
        'period_number',
        'period_start',
        'service_number',
        'observations',
        'has_alert',
        'alert_flagged_at',
        'import_row_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'year'             => 'integer',
            'period_number'    => 'integer',
            'period_start'     => 'date',
            'service_number'   => 'integer',
            'observations'     => 'encrypted', // puede traer información sensible de la familia
            'has_alert'        => 'boolean',
            'alert_flagged_at' => 'datetime',
        ];
    }

    /**
     * Mantiene period_start sincronizado con año/tipo/número, y alert_flagged_at
     * con has_alert: se sella cada vez que la prestación pasa a tener alerta (una
     * alerta que ya estaba marcada no se vuelve a sellar al editar otra cosa).
     */
    protected static function booted(): void
    {
        static::saving(function (ChildService $service) {
            if ($service->isDirty(['year', 'period_type', 'period_number']) || $service->period_start === null) {
                $service->period_start = ServicePeriod::start(
                    $service->period_type,
                    (int) $service->period_number,
                    (int) $service->year,
                );
            }

            if ($service->has_alert && ($service->isDirty('has_alert') || $service->alert_flagged_at === null)) {
                $service->alert_flagged_at = now();
            }

            if (! $service->has_alert) {
                $service->alert_flagged_at = null;
            }
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        // observations queda afuera del historial de auditoría (dato sensible, cifrado).
        return LogOptions::defaults()
            ->logOnly([
                'service_type_id', 'institution_id', 'sector', 'year', 'period_type',
                'period_number', 'service_number', 'has_alert',
            ])
            ->logOnlyDirty();
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** Clave de período para agrupar/filtrar: "2026-trimestre-1". */
    public function periodKey(): string
    {
        return "{$this->year}-{$this->period_type}-{$this->period_number}";
    }
}
