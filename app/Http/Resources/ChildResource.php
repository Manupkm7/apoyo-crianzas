<?php

namespace App\Http\Resources;

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

        return [
            'id'         => $this->id,
            'first_name' => $this->first_name,
            'last_name'  => $this->last_name,
            'birth_date' => $this->birth_date?->toDateString(),
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

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
