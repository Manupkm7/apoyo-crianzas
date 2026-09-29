<?php

namespace App\Services;

use App\Contracts\SystemActor;
use App\Models\AlertAcknowledgement;
use App\Models\Child;
use App\Models\ChildService;
use App\Models\ServiceType;
use App\Support\ServicePeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Sistema de Alerta Temprana (SAT) — cálculo de alertas de un niño.
 *
 * Las alertas NO se guardan: se calculan al vuelo a partir de
 *   1) la foto vigente   → education_records / health_records
 *   2) el último bimestre → EducationRecord/HealthRecord::latestPeriodReport
 *   3) las prestaciones por período → child_services (sector 'prestaciones')
 *
 * Una alerta de educación/salud salta si CUALQUIERA de las dos primeras fuentes
 * marca el problema. Ej.: el efector dice "vacunas al día" pero el último
 * reporte bimestral dice "atrasadas" → hay alerta.
 *
 * Alertas de prestaciones (solo visibles para admin/coordinador — las
 * prestaciones mezclan todos los sectores y pueden traer datos sensibles):
 *   - alerta_prestacion: algún efector marcó "Alerta = SI" en una prestación
 *     (archivo o carga manual). Queda pendiente hasta que alguien la gestiona
 *     DESPUÉS de que se marcó (alert_flagged_at); una prestación nueva con
 *     alerta la vuelve a poner en pendiente aunque haya una gestión vigente.
 *     Una vez revisada no reaparece sola al vencer la gestión.
 *   - prestacion_obligatoria_faltante: en el ÚLTIMO período informado del niño
 *     falta alguna prestación marcada como obligatoria en el catálogo
 *     (service_types.is_mandatory). Sigue el ciclo normal de gestión (se
 *     silencia y vuelve si el faltante persiste). Un niño sin ninguna
 *     prestación cargada no alerta: sin dato no hay alarma.
 *
 * "Gestionar" una alerta (App\Models\AlertAcknowledgement) la silencia por
 * config('alerts.acknowledgement_ttl_days') días: durante ese plazo queda como
 * 'managed' (en seguimiento) en vez de 'pending'. Vencido el plazo, si el
 * problema sigue, vuelve a 'pending'.
 */
class ChildAlertEvaluator
{
    public const TYPE_NO_ESCOLARIZADO       = 'no_escolarizado';
    public const TYPE_INASISTENCIAS         = 'inasistencias_elevadas';
    public const TYPE_CONTROL_ATRASADO      = 'control_atrasado';
    public const TYPE_VACUNAS_ATRASADAS     = 'vacunas_atrasadas';
    public const TYPE_ALERTA_PRESTACION     = 'alerta_prestacion';
    public const TYPE_PRESTACION_FALTANTE   = 'prestacion_obligatoria_faltante';

    /** Sector "virtual" de las alertas que salen de child_services. */
    public const SECTOR_PRESTACIONES = 'prestaciones';

    /** tipo => [sector, etiqueta legible] */
    public const TYPES = [
        self::TYPE_NO_ESCOLARIZADO     => ['educacion', 'No escolarizado'],
        self::TYPE_INASISTENCIAS       => ['educacion', 'Inasistencias elevadas'],
        self::TYPE_CONTROL_ATRASADO    => ['salud', 'Control de niño sano atrasado'],
        self::TYPE_VACUNAS_ATRASADAS   => ['salud', 'Vacunas atrasadas'],
        self::TYPE_ALERTA_PRESTACION   => [self::SECTOR_PRESTACIONES, 'Alerta informada por un efector'],
        self::TYPE_PRESTACION_FALTANTE => [self::SECTOR_PRESTACIONES, 'Falta una prestación obligatoria'],
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

            if ($sector === self::SECTOR_PRESTACIONES) {
                $alert = $this->evaluateServiceAlert($type, $label);
                if ($alert !== null) {
                    $alerts[] = $alert;
                }
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
                'details'    => [],
                'status'     => $active ? 'managed' : 'pending',
                'management' => $active ? $this->formatAck($active) : null,
                'history'    => $acks
                    ->map(fn (AlertAcknowledgement $a) => $this->formatAck($a) + ['active' => $a->isActive()])
                    ->all(),
            ];
        }

        return $alerts;
    }

    // ── alertas de prestaciones (requiere services.serviceType + services.institution) ──

    /**
     * Arma la alerta de prestaciones de este tipo, o null si no corresponde.
     * 'details' lista, en texto legible, qué la dispara (prestaciones con alerta
     * o prestaciones obligatorias que faltan).
     */
    private function evaluateServiceAlert(string $type, string $label): ?array
    {
        $acks   = $this->acksForType($type);
        $active = $acks->first(fn (AlertAcknowledgement $a) => $a->isActive());

        $result = $type === self::TYPE_ALERTA_PRESTACION
            ? $this->flaggedServicesState($acks, $active)
            : $this->missingMandatoryState($active);

        if ($result === null) {
            return null;
        }

        return [
            'type'       => $type,
            'sector'     => self::SECTOR_PRESTACIONES,
            'label'      => $label,
            'sources'    => ['services'],
            'period'     => $result['period'],
            'details'    => $result['details'],
            'status'     => $result['status'],
            'management' => $active ? $this->formatAck($active) : null,
            'history'    => $acks
                ->map(fn (AlertAcknowledgement $a) => $this->formatAck($a) + ['active' => $a->isActive()])
                ->all(),
        ];
    }

    /**
     * Prestaciones con alerta. Pendiente si hay alguna marcada después de la
     * última gestión (o nunca gestionada); en seguimiento si todas ya fueron
     * revisadas y la gestión sigue vigente; si no, no se muestra.
     *
     * @return array{status: string, period: ?string, details: list<string>}|null
     */
    private function flaggedServicesState(Collection $acks, ?AlertAcknowledgement $active): ?array
    {
        $flagged = $this->child->services
            ->filter(fn (ChildService $s) => $s->has_alert)
            ->sortByDesc(fn (ChildService $s) => $s->alert_flagged_at?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        if ($flagged->isEmpty()) {
            return null;
        }

        $lastAckAt  = $acks->first()?->acknowledged_at;
        $unreviewed = $flagged->filter(
            fn (ChildService $s) => $lastAckAt === null
                || $s->alert_flagged_at === null
                || $s->alert_flagged_at->greaterThan($lastAckAt)
        )->values();

        if ($unreviewed->isNotEmpty()) {
            $shown  = $unreviewed;
            $status = 'pending';
        } elseif ($active) {
            $shown  = $flagged->filter(
                fn (ChildService $s) => $s->alert_flagged_at === null || $s->alert_flagged_at->lessThanOrEqualTo($active->acknowledged_at)
            )->values();
            $status = 'managed';
        } else {
            return null;
        }

        $first = $shown->first();

        return [
            'status'  => $status,
            'period'  => $first ? ServicePeriod::label($first->period_type, $first->period_number, $first->year) : null,
            'details' => $shown->take(10)->map(fn (ChildService $s) => $this->describeService($s, withObservations: true))->all(),
        ];
    }

    /**
     * Prestaciones obligatorias ausentes en el último período informado.
     *
     * @return array{status: string, period: ?string, details: list<string>}|null
     */
    private function missingMandatoryState(?AlertAcknowledgement $active): ?array
    {
        $services = $this->child->services;

        if ($services->isEmpty()) {
            return null;
        }

        $missing = self::missingMandatoryTypes($services);

        if ($missing->isEmpty()) {
            return null;
        }

        $latest = self::latestPeriodServices($services)->first();

        return [
            'status'  => $active ? 'managed' : 'pending',
            'period'  => ServicePeriod::label($latest->period_type, $latest->period_number, $latest->year),
            'details' => $missing->map(fn (ServiceType $t) => $t->name)->values()->all(),
        ];
    }

    /**
     * Prestaciones del último período informado (el de period_start más
     * reciente; si un trimestre y un bimestre arrancan el mismo día, cuentan
     * los dos).
     *
     * @param  Collection<int, ChildService>  $services
     * @return Collection<int, ChildService>
     */
    private static function latestPeriodServices(Collection $services): Collection
    {
        $latestStart = $services->max(fn (ChildService $s) => $s->period_start->getTimestamp());

        return $services
            ->filter(fn (ChildService $s) => $s->period_start->getTimestamp() === $latestStart)
            ->values();
    }

    /**
     * @param  Collection<int, ChildService>  $services
     * @return Collection<int, ServiceType>
     */
    private static function missingMandatoryTypes(Collection $services): Collection
    {
        $present = self::latestPeriodServices($services)->pluck('service_type_id')->unique();

        return self::mandatoryTypes()
            ->reject(fn (ServiceType $t) => $present->contains($t->id))
            ->values();
    }

    /**
     * Catálogo de prestaciones obligatorias activas — una sola consulta por
     * request aunque se evalúen muchos niños (listado).
     *
     * @return Collection<int, ServiceType>
     */
    private static function mandatoryTypes(): Collection
    {
        return once(fn () => ServiceType::mandatory()->orderBy('name')->get());
    }

    private function describeService(ChildService $s, bool $withObservations = false): string
    {
        $text = ($s->serviceType?->name ?? 'Prestación')
            . ($s->institution ? " ({$s->institution->name})" : '')
            . ' · ' . ServicePeriod::label($s->period_type, $s->period_number, $s->year);

        if ($withObservations && $s->observations) {
            $text .= ' — ' . Str::limit($s->observations, 200);
        }

        return $text;
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

        if ($sector === self::SECTOR_PRESTACIONES) {
            // Para gestionar alcanza con que haya alguna prestación con alerta (aunque
            // ya esté en seguimiento: se puede actualizar la nota, igual que el resto).
            if ($type === self::TYPE_ALERTA_PRESTACION) {
                return $child->services()->where('has_alert', true)->exists();
            }

            return Child::whereKey($child->id)
                ->where(fn (Builder $q) => self::applyServiceCondition($q, $type))
                ->exists();
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
        if (self::sectorForType($type) === self::SECTOR_PRESTACIONES) {
            $child->loadMissing(['services.serviceType', 'services.institution', 'alertAcknowledgements']);
            $alert = (new self($child))->evaluateServiceAlert($type, (string) self::labelForType($type));

            return array_filter(
                ['sources' => ['services'], 'period' => $alert['period'] ?? null, 'details' => $alert['details'] ?? []],
                fn ($v) => $v !== null && $v !== [],
            );
        }

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
                // Prestaciones: solo admin/coordinador (ver docblock de la clase).
                if ($sector === self::SECTOR_PRESTACIONES) {
                    if ($user->canBypassRls()) {
                        $outer->orWhere(function (Builder $c) use ($type) {
                            self::applyServiceCondition($c, $type);

                            // alerta_prestacion ya excluye en su propia condición lo
                            // revisado; el faltante sigue el ciclo normal de gestión.
                            if ($type === self::TYPE_PRESTACION_FALTANTE) {
                                $c->whereDoesntHave('alertAcknowledgements', function (Builder $aq) use ($type) {
                                    $aq->where('alert_type', $type)->where('expires_at', '>', now());
                                });
                            }
                        });
                    }
                    continue;
                }

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
     * Sobre una query de Child: condición SQL de las alertas de prestaciones,
     * equivalente a flaggedServicesState()/missingMandatoryState().
     *
     *   - alerta_prestacion: existe una prestación con alerta que no tenga ninguna
     *     gestión posterior a cuando se marcó.
     *   - prestacion_obligatoria_faltante: el niño tiene prestaciones cargadas y
     *     en su último período informado falta alguna obligatoria activa.
     */
    public static function applyServiceCondition(Builder $childQuery, string $type): void
    {
        if ($type === self::TYPE_ALERTA_PRESTACION) {
            $childQuery->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('child_services as cs')
                    ->whereColumn('cs.child_id', 'children.id')
                    ->whereNull('cs.deleted_at')
                    ->where('cs.has_alert', true)
                    ->whereNotExists(function ($a) {
                        $a->selectRaw('1')
                            ->from('alert_acknowledgements as aa')
                            ->whereColumn('aa.child_id', 'cs.child_id')
                            ->where('aa.alert_type', self::TYPE_ALERTA_PRESTACION)
                            ->whereColumn('aa.acknowledged_at', '>=', 'cs.alert_flagged_at');
                    });
            });

            return;
        }

        $latestStart = '(SELECT MAX(cs2.period_start) FROM child_services cs2 WHERE cs2.child_id = children.id AND cs2.deleted_at IS NULL)';

        $childQuery
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('child_services as cs0')
                    ->whereColumn('cs0.child_id', 'children.id')
                    ->whereNull('cs0.deleted_at');
            })
            ->whereExists(function ($q) use ($latestStart) {
                $q->selectRaw('1')
                    ->from('service_types as st')
                    ->where('st.is_mandatory', true)
                    ->where('st.is_active', true)
                    ->whereNotExists(function ($s) use ($latestStart) {
                        $s->selectRaw('1')
                            ->from('child_services as cs')
                            ->whereColumn('cs.child_id', 'children.id')
                            ->whereColumn('cs.service_type_id', 'st.id')
                            ->whereNull('cs.deleted_at')
                            ->whereRaw("cs.period_start = {$latestStart}");
                    });
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
