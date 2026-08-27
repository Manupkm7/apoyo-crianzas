<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HealthPeriodReportRequest;
use App\Http\Resources\HealthPeriodReportResource;
use App\Models\Child;
use App\Models\HealthPeriodReport;
use App\Models\HealthRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HealthPeriodReportController — Reportes bimestrales del registro de salud.
 *
 *   GET    /children/{child}/health-record/periods            → listar (?year=&bimester=)
 *   POST   /children/{child}/health-record/periods            → cargar un bimestre
 *   PATCH  /children/{child}/health-record/periods/{report}   → corregir un bimestre
 *   DELETE /children/{child}/health-record/periods/{report}   → borrar un bimestre (solo admin)
 *
 * Mismo patrón que HealthObservationController: el registro se resuelve según el
 * contexto del usuario y la autorización fina la hace HealthRecordPolicy.
 */
class HealthPeriodReportController extends Controller
{
    public function index(Request $request, Child $child): JsonResponse
    {
        $record = $this->resolveRecord($request, $child);

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro de salud.'], 404);
        }

        $this->authorize('view', $record);

        $reports = $record->periodReports()
            ->when($request->filled('year'), fn ($q) => $q->where('year', (int) $request->query('year')))
            ->when($request->filled('bimester'), fn ($q) => $q->where('bimester', (int) $request->query('bimester')))
            ->get();

        return response()->json(HealthPeriodReportResource::collection($reports));
    }

    public function store(HealthPeriodReportRequest $request, Child $child): JsonResponse
    {
        $record = $this->resolveRecord($request, $child);

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro de salud.'], 404);
        }

        $this->authorize('update', $record);

        $data = $request->validated();

        $exists = $record->periodReports()
            ->where('year', $data['year'])
            ->where('bimester', $data['bimester'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'Ya hay un reporte cargado para ese bimestre. Editá el existente.',
            ], 409);
        }

        $report = $record->periodReports()->create([
            ...$data,
            'created_by' => $request->user()->auditId(),
        ]);

        return response()->json(new HealthPeriodReportResource($report), 201);
    }

    public function update(HealthPeriodReportRequest $request, Child $child, HealthPeriodReport $report): JsonResponse
    {
        $record = $this->resolveRecord($request, $child);

        if (! $record || $report->health_record_id !== $record->id) {
            abort(404);
        }

        $this->authorize('update', $record);

        $report->update([
            ...$request->validated(),
            'updated_by' => $request->user()->auditId(),
        ]);

        return response()->json(new HealthPeriodReportResource($report));
    }

    public function destroy(Request $request, Child $child, HealthPeriodReport $report): JsonResponse
    {
        $record = $this->resolveRecord($request, $child);

        if (! $record || $report->health_record_id !== $record->id) {
            abort(404);
        }

        $this->authorize('delete', $record);

        $report->update(['updated_by' => $request->user()->auditId()]);
        $report->delete();

        return response()->json(['message' => 'Reporte del bimestre eliminado.']);
    }

    private function resolveRecord(Request $request, Child $child): ?HealthRecord
    {
        $user = $request->user();

        return $user->canBypassRls()
            ? $child->healthRecord()->first()
            : $child->healthRecord()->where('institution_id', $user->institution_id)->first();
    }
}
