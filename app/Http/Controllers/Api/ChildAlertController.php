<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcknowledgeAlertRequest;
use App\Models\Child;
use App\Services\ChildAlertEvaluator;
use Illuminate\Http\JsonResponse;

/**
 * ChildAlertController — Gestión de alertas del Sistema de Alerta Temprana.
 *
 *   POST /children/{child}/alerts/{type}/acknowledge
 *
 * Marca una alerta como "gestionada / en seguimiento": se detectó y se coordinó
 * un control fuera de la plataforma. La alerta deja de contar como pendiente
 * durante config('alerts.acknowledgement_ttl_days') días; pasado el plazo, si el
 * problema persiste, vuelve a aparecer como pendiente.
 *
 * Quién puede: la institución dueña del registro del sector (con permiso de
 * gestión de niños) o el admin — misma regla que editar ese registro, de ahí
 * que se reutilice Education/HealthRecordPolicy::update. Las alertas de
 * prestaciones (alerta_prestacion, prestacion_obligatoria_faltante) solo el admin.
 */
class ChildAlertController extends Controller
{
    public function acknowledge(AcknowledgeAlertRequest $request, Child $child, string $type): JsonResponse
    {
        $sector = ChildAlertEvaluator::sectorForType($type);
        abort_if($sector === null, 404, 'Tipo de alerta desconocido.');

        if ($sector === ChildAlertEvaluator::SECTOR_PRESTACIONES) {
            // Las alertas de prestaciones cruzan todos los sectores y efectores (no
            // hay una "institución dueña"): solo las gestiona el admin.
            abort_unless(
                $request->user()->hasRole('admin'),
                403,
                'Solo el administrador puede gestionar alertas de prestaciones.',
            );
        } else {
            $child->load([
                'educationRecord.latestPeriodReport',
                'healthRecord.latestPeriodReport',
            ]);

            $record = $sector === 'educacion' ? $child->educationRecord : $child->healthRecord;
            abort_if($record === null, 404, 'El niño no tiene registro en ese sector.');

            // "Institución dueña del registro + admin" (coordinador queda afuera:
            // Education/HealthRecordPolicy::update no lo contempla).
            $this->authorize('update', $record);
        }

        abort_unless(
            ChildAlertEvaluator::conditionHolds($child, $type),
            422,
            'Esta alerta ya no está activa: no hace falta gestionarla.',
        );

        $user = $request->user();
        $ttl  = (int) config('alerts.acknowledgement_ttl_days', 60);

        $child->alertAcknowledgements()->create([
            'alert_type'                     => $type,
            'sector'                         => $sector,
            'note'                           => $request->validated()['note'],
            'acknowledged_by'                => $user->auditId(),
            'acknowledged_by_institution_id' => $user->isInstitutionalUser() ? $user->institution_id : null,
            'acknowledged_at'                => now(),
            'expires_at'                     => now()->addDays($ttl),
            'context'                        => ChildAlertEvaluator::contextSnapshot($child, $type),
            'created_by'                     => $user->auditId(),
        ]);

        return response()->json([
            'message' => "Alerta marcada como gestionada. Vuelve a aparecer en {$ttl} días si el problema persiste.",
        ], 201);
    }
}
