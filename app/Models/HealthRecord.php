<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'health_profile',
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
            ->logOnly(['institution_id', 'health_center_name', 'healthy_checkup_current', 'vaccines_current', 'last_checkup_date', 'observations'])
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
        return $this->hasMany(HealthObservation::class)->latest();
    }

    /**
     * Reportes bimestrales (histórico). El registro en sí es la foto vigente;
     * estos son el detalle por bimestre, del más reciente al más viejo.
     */
    public function periodReports(): HasMany
    {
        return $this->hasMany(HealthPeriodReport::class)
            ->orderByDesc('year')
            ->orderByDesc('bimester');
    }

    /**
     * El último bimestre informado (mayor año, luego mayor bimestre). Es lo que
     * mira el SAT además de la foto vigente: si el último reporte dice control o
     * vacunas "atrasado", hay alerta aunque el registro vigente diga "al día".
     * Ver App\Services\ChildAlertEvaluator.
     *
     * Se resuelve con comparación de fila `(year, bimester) = (subconsulta)` en
     * vez de hasOneOfMany porque este último agrega MAX(id) como desempate y el
     * id es UUID (Postgres no tiene max(uuid)).
     */
    public function latestPeriodReport(): HasOne
    {
        return $this->hasOne(HealthPeriodReport::class)->whereRaw(
            '(health_period_reports.year, health_period_reports.bimester) = '
            . '(select t.year, t.bimester from health_period_reports as t '
            . 'where t.health_record_id = health_period_reports.health_record_id '
            . 'and t.deleted_at is null '
            . 'order by t.year desc, t.bimester desc limit 1)'
        );
    }
}
