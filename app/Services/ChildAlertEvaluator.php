<?php

namespace App\Services;

use App\Contracts\SystemActor;
use App\Models\AlertAcknowledgement;
use App\Models\Child;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sistema de Alerta Temprana (SAT) — cálculo de alertas de un niño.
 *
 * Las alertas NO se guardan: se calculan al vuelo a partir de
 *   1) la foto vigente   → education_records / health_records
 *   2) el último bimestre → EducationRecord/HealthRecord::latestPeriodReport
 *
 * Una alerta salta si CUALQUIERA de las dos fuentes marca el problema. Ej.: el
 * efector dice "vacunas al día" pero el último reporte bimestral dice
 * "atrasadas" → hay alerta.
 *
 * "Gestionar" una alerta (App\Models\AlertAcknowledgement) la silencia por
 * config('alerts.acknowledgement_ttl_days') días: durante ese plazo queda como
 * 'managed' (en seguimiento) en vez de 'pending'. Vencido el plazo, si el
 * problema sigue, vuelve a 'pending'.
 */
class ChildAlertEvaluator
{
    public const TYPE_NO_ESCOLARIZADO   = 'no_escolarizado';
    public const TYPE_INASISTENCIAS     = 'inasistencias_elevadas';
    public const TYPE_CONTROL_ATRASADO  = 'control_atrasado';
    public const TYPE_VACUNAS_ATRASADAS = 'vacunas_atrasadas';

    /** tipo => [sector, etiqueta legible] */
    public const TYPES = [
        self::TYPE_NO_ESCOLARIZADO   => ['educacion', 'No escolarizado'],
        self::TYPE_INASISTENCIAS     => ['educacion', 'Inasistencias elevadas'],
        self::TYPE_CONTROL_ATRASADO  => ['salud', 'Control de niño sano atrasado'],
        self::TYPE_VACUNAS_ATRASADAS => ['salud', 'Vacunas atrasadas'],
    ];

    public function __construct(private Child $child)
    {
    }

    // ── helpers de catálogo ─────────────────────────────────────────────────

    public static function absenceThreshold(): int
    {
        return (int) config('alerts.absence_threshold', 10);
    }

    public static function sectorForType(string $type): ?string
    {
        return self::TYPES[$type][0] ?? null;
    }

    public static function labelForType(string $type): ?string
    {
        return self::TYPES[$type][1] ?? null;
    }

    /** @return list<string> */
    public static function typesForSector(string $sector): array
    {
        return array_keys(array_filter(self::TYPES, fn ($def) => $def[0] === $sector));
    }

    // ── cálculo en memoria (requiere relaciones cargadas) ───────────────────

    /**
     * Alertas del niño en los sectores indicados (el consumidor pasa solo los
     * que el usuario puede ver). Cada alerta trae su estado de gestión.
     *
     * @param  list<string>  $sectors
     * @return list<array<string,mixed>>
     */
    public function evaluate(array $sectors): array
    {
        $alerts = [];

        foreach (self::TYPES as $type => [$sector, $label]) {
            if (! in_array($sector, $sectors, true)) {
                continue;
            }

            $cond = $this->conditionSources($type);
            if ($cond['sources'] === []) {
                continue;
            }

            $acks   = $this->acksForType($type);
            $active = $acks->first(fn (AlertAcknowledgement $a) => $a->isActive());

            $alerts[] = [
                'type'       => $type,
                'sector'     => $sector,
                'label'      => $label,
                'sources'    => $cond['sources'],
                'period'     => $cond['period'],
                'status'     => $active ? 'managed' : 'pending',
                'management' => $active ? $this->formatAck($active) : null,
                'history'    => $acks
                    ->map(fn (AlertAcknowledgement $a) => $this->formatAck($a) + ['active' => $a->isActive()])
                    ->all(),
            ];
        }

        return $alerts;
    }

    /**
     * ¿Hay al menos una alerta PENDIENTE (sin gestión vigente) en estos sectores?
     *
     * @param  list<string>  $sectors
     */
    public function hasPending(array $sectors): bool
    {
        foreach ($this->evaluate($sectors) as $alert) {
            if ($alert['status'] === 'pending') {
                return true;
            }
        }

        return false;
    }

    /**
     * Fuentes donde la condición del tipo se cumple ahora mismo ('record' y/o
     * 'period'), más el período del último bimestre si es una de las fuentes.
     *
     * @return array{sources: list<string>, period: ?string}
     */
    private function conditionSources(string $type): array
    {
        $sector = self::TYPES[$type][0];
        $record = $sector === 'educacion' ? $this->child->educationRecord : $this->child->healthRecord;

        if (! $record) {
            return ['sources' => [], 'period' => null];
        }

        $threshold = self::absenceThreshold();
        $period    = $record->latestPeriodReport;
        $sources   = [];

        $recordHit = match ($type) {
            self::TYPE_NO_ESCOLARIZADO   => $record->is_enrolled === false,
            self::TYPE_INASISTENCIAS     => $record->absences_count !== null && $record->absences_count > $threshold,
            self::TYPE_CONTROL_ATRASADO  => $record->healthy_checkup_current === false,
            self::TYPE_VACUNAS_ATRASADAS => $record->vaccines_current === false,
        };
        if ($recordHit) {
            $sources[] = 'record';
        }

        $periodHit = $period !== null && match ($type) {
            self::TYPE_NO_ESCOLARIZADO   => $period->is_enrolled === false,
            self::TYPE_INASISTENCIAS     => $period->absences_count !== null && $period->absences_count > $threshold,
            self::TYPE_CONTROL_ATRASADO  => $period->healthy_checkup_current === false,
            self::TYPE_VACUNAS_ATRASADAS => $period->vaccines_current === false,
        };
        if ($periodHit) {
            $sources[] = 'period';
        }

        return [
            'sources' => $sources,
            'period'  => $periodHit ? "{$period->year}-{$period->bimester}" : null,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, AlertAcknowledgement> */
    private function acksForType(string $type)
    {
        return $this->child->alertAcknowledgements
            ->where('alert_type', $type)
            ->sortByDesc('acknowledged_at')
            ->values();
    }

    private function formatAck(AlertAcknowledgement $ack): array
    {
        return [
            'note'            => $ack->note,
            'by'              => $ack->acknowledgedByUser?->name
                ?? $ack->acknowledgedByInstitution?->name,
            'acknowledged_at' => $ack->acknowledged_at?->toISOString(),
            'expires_at'      => $ack->expires_at?->toISOString(),
        ];
    }

    // ── consultas directas (no dependen de relaciones cargadas) ─────────────

    /**
     * ¿La condición de esta alerta se cumple para el niño? La usa el controlador
     * antes de aceptar una gestión: no se gestiona lo que no está alertando.
     */
    public static function conditionHolds(Child $child, string $type): bool
    {
        $sector = self::sectorForType($type);
        if ($sector === null) {
            return false;
        }

        $relation = $sector === 'educacion' ? 'educationRecord' : 'healthRecord';

        return $child->{$relation}()
            ->where(fn (Builder $q) => self::applyTypeCondition($q, $type))
            ->exists();
    }

    /**
     * ¿Hay una gestión vigente para este (niño, tipo)?
     */
    public static function hasActiveAcknowledgement(Child $child, string $type): bool
    {
        return $child->alertAcknowledgements()
            ->where('alert_type', $type)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * ¿El niño tiene alguna alerta PENDIENTE en este sector? Consulta directa,
     * para ChildController::show (aviso "alerta en el otro sector, sin detalle").
     */
    public static function sectorHasPendingAlert(Child $child, string $sector): bool
    {
        foreach (self::typesForSector($sector) as $type) {
            if (self::conditionHolds($child, $type) && ! self::hasActiveAcknowledgement($child, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Snapshot de las fuentes que dispararon la alerta, para guardar junto a la
     * gestión (columna context).
     */
    public static function contextSnapshot(Child $child, string $type): array
    {
        $cond = (new self($child))->conditionSources($type);

        return array_filter(
            ['sources' => $cond['sources'], 'period' => $cond['period']],
            fn ($v) => $v !== null && $v !== [],
        );
    }

    // ── scope SQL para el listado ──────────────────────────────────────────

    /**
     * Restringe una query de Child a los que tienen alguna alerta PENDIENTE
     * visible para $user (misma definición que evaluate(): foto vigente o último
     * bimestre, menos las que tienen gestión vigente). Se usa en
     * ChildController::index para el filtro ?alert=1 y el conteo alerts_count.
     */
    public static function applyPendingAlertScope(Builder $query, SystemActor $user): void
    {
        $query->where(function (Builder $outer) use ($user) {
            foreach (self::TYPES as $type => [$sector]) {
                if (! ($user->canBypassRls() || $user->institutionType() === $sector)) {
                    continue;
                }

                $relation = $sector === 'educacion' ? 'educationRecord' : 'healthRecord';

                $outer->orWhere(function (Builder $c) use ($user, $type, $relation) {
                    $c->whereHas($relation, function (Builder $rq) use ($user, $type) {
                        if (! $user->canBypassRls()) {
                            $rq->where('institution_id', $user->institution_id);
                        }
                        $rq->where(fn (Builder $w) => self::applyTypeCondition($w, $type));
                    })->whereDoesntHave('alertAcknowledgements', function (Builder $aq) use ($type) {
                        $aq->where('alert_type', $type)->where('expires_at', '>', now());
                    });
                });
            }
        });
    }

    /**
     * Sobre una query de EducationRecord o HealthRecord: la condición del tipo se
     * cumple si la foto vigente la marca O si el último bimestre informado la
     * marca.
     */
    public static function applyTypeCondition(Builder $recordQuery, string $type): void
    {
        $threshold = self::absenceThreshold();

        match ($type) {
            self::TYPE_NO_ESCOLARIZADO => $recordQuery
                ->where('is_enrolled', false)
                ->orWhereHas('latestPeriodReport', fn (Builder $p) => $p->where('is_enrolled', false)),

            self::TYPE_INASISTENCIAS => $recordQuery
                ->where('absences_count', '>', $threshold)
                ->orWhereHas('latestPeriodReport', fn (Builder $p) => $p->where('absences_count', '>', $threshold)),

            self::TYPE_CONTROL_ATRASADO => $recordQuery
                ->where('healthy_checkup_current', false)
                ->orWhereHas('latestPeriodReport', fn (Builder $p) => $p->where('healthy_checkup_current', false)),

            self::TYPE_VACUNAS_ATRASADAS => $recordQuery
                ->where('vaccines_current', false)
                ->orWhereHas('latestPeriodReport', fn (Builder $p) => $p->where('vaccines_current', false)),
        };
    }
}
