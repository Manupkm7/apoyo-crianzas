<?php

namespace App\Http\Resources;

use App\Services\ChildAlertEvaluator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ChildResource — Controla qué datos de un niño se envían al frontend.
 *
 * Dependiendo del tipo de institución del usuario, se incluyen distintos datos:
 * - Admin/coordinador: ven el niño completo + registros de educación y salud si existen.
 * - Institución educativa: ven el niño + su registro educativo.
 * - Institución de salud: ven el niño + su registro de salud.
 *
 * El DNI nunca se envía en el listado general (/children), solo en el detalle
 * individual (/children/{id}) y solo para usuarios con canBypassRls().
 */
class ChildResource extends JsonResource
{
    /**
     * Indica si el DNI debe incluirse en la respuesta.
     * Se activa manualmente en el controlador para el endpoint de detalle.
     */
    public bool $includeDni = false;

    public static function collection($resource): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        return parent::collection($resource);
    }

    public function withDni(): static
    {
        $this->includeDni = true;
        return $this;
    }

    public function toArray(Request $request): array
    {
        $user = $request->user();

        // Solo evaluamos alertas de los sectores que el usuario tiene cargados
        // (mismo criterio de RLS que arma el controlador).
        $sectors = [];
        if ($this->relationLoaded('educationRecord') && $this->educationRecord) {
            $sectors[] = 'educacion';
        }
        if ($this->relationLoaded('healthRecord') && $this->healthRecord) {
            $sectors[] = 'salud';
        }

        $alerts     = (new ChildAlertEvaluator($this->resource))->evaluate($sectors);
        $hasPending = collect($alerts)->contains(fn (array $a) => $a['status'] === 'pending');

        return [
            'id'         => $this->id,
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'birth_date' => $this->birth_date?->toDateString(),
            // true cuando la fecha es un placeholder (1/1/2000) puesto por el sistema
            // porque el archivo importado no la traía — ver ImportController::GENERIC_BIRTH_DATE.
            // El frontend debe mostrar una advertencia para que se corrija a mano.
            'birth_date_is_placeholder' => (bool) $this->birth_date_is_placeholder,
            'age'        => $this->age,

            // El DNI solo se muestra en el endpoint de detalle para admins y coordinadores
            'dni'        => $this->when(
                $this->includeDni && $user->canBypassRls(),
                $this->dni
            ),

            'notes'      => $this->notes,

            // Registro educativo — solo se incluye si se cargó la relación
            'education_record' => $this->whenLoaded('educationRecord', function () {
                return $this->educationRecord
                    ? new EducationRecordResource($this->educationRecord)
                    : null;
            }),

            // Registro de salud — solo se incluye si se cargó la relación
            'health_record' => $this->whenLoaded('healthRecord', function () {
                return $this->healthRecord
                    ? new HealthRecordResource($this->healthRecord)
                    : null;
            }),

            // Registro de nacimiento — solo admin/coordinador (ver BirthRecordPolicy)
            'birth_record' => $this->whenLoaded('birthRecord', function () {
                return $this->birthRecord
                    ? new BirthRecordResource($this->birthRecord)
                    : null;
            }),

            // Registro de defunción — solo admin/coordinador (ver DeathRecordPolicy)
            'death_record' => $this->whenLoaded('deathRecord', function () {
                return $this->deathRecord
                    ? new DeathRecordResource($this->deathRecord)
                    : null;
            }),

            // Alertas del SAT calculadas a partir de los registros que el usuario
            // puede ver (foto vigente + último bimestre informado), cada una con
            // su estado de gestión ('pending' | 'managed'). Ver ChildAlertEvaluator.
            'alerts'    => $alerts,
            // has_alert = hay al menos una alerta PENDIENTE (las gestionadas no cuentan).
            'has_alert' => $hasPending,

            // Solo presente en el detalle para usuarios institucionales: indica si el niño
            // tiene una alerta pendiente en el OTRO sector, sin revelar el detalle (ver ChildController::show).
            'other_sector_alert' => $this->when(
                ! is_null($this->other_sector_alert),
                fn () => (bool) $this->other_sector_alert
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
