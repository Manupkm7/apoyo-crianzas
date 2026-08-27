<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EducationPeriodReportRequest;
use App\Http\Resources\EducationPeriodReportResource;
use App\Models\Child;
use App\Models\EducationPeriodReport;
use App\Models\EducationRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EducationPeriodReportController — Reportes bimestrales del registro educativo.
 *
 *   GET    /children/{child}/education-record/periods            → listar (?year=&bimester=)
 *   POST   /children/{child}/education-record/periods            → cargar un bimestre
 *   PATCH  /children/{child}/education-record/periods/{report}   → corregir un bimestre
 *   DELETE /children/{child}/education-record/periods/{report}   → borrar un bimestre (solo admin)
 *
 * El registro educativo se resuelve según el contexto del usuario, igual que en
 * EducationObservationController: admin/coordinador ven cualquiera; una
 * institución educativa solo el suyo. La autorización fina la hace
 * EducationRecordPolicy (view para leer, update para escribir, delete para borrar).
 */
class EducationPeriodReportController extends Controller
{
    public function index(Request $request, Child $child): JsonResponse
    {
        $record = $this->resolveRecord($request, $child);

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro educativo.'], 404);
        }

        $this->authorize('view', $record);

        $reports = $record->periodReports()
            ->when($request->filled('year'), fn ($q) => $q->where('year', (int) $request->query('year')))
            ->when($request->filled('bimester'), fn ($q) => $q->where('bimester', (int) $request->query('bimester')))
            ->get();

        return response()->json(EducationPeriodReportResource::collection($reports));
    }

    public function store(EducationPeriodReportRequest $request, Child $child): JsonResponse
    {
        $record = $this->resolveRecord($request, $child);

        if (! $record) {
            return response()->json(['message' => 'Este niño no tiene registro educativo.'], 404);
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

        return response()->json(new EducationPeriodReportResource($report), 201);
    }

    public function update(EducationPeriodReportRequest $request, Child $child, EducationPeriodReport $report): JsonResponse
    {
        $record = $this->resolveRecord($request, $child);

        if (! $record || $report->education_record_id !== $record->id) {
            abort(404);
        }

        $this->authorize('update', $record);

        $report->update([
            ...$request->validated(),
            'updated_by' => $request->user()->auditId(),
        ]);

        return response()->json(new EducationPeriodReportResource($report));
    }

    public function destroy(Request $request, Child $child, EducationPeriodReport $report): JsonResponse
    {
        $record = $this->resolveRecord($request, $child);

        if (! $record || $report->education_record_id !== $record->id) {
            abort(404);
        }

        $this->authorize('delete', $record);

        $report->update(['updated_by' => $request->user()->auditId()]);
        $report->delete();

        return response()->json(['message' => 'Reporte del bimestre eliminado.']);
    }

    /**
     * Registro educativo del niño según el contexto del usuario (mismo criterio
     * que EducationObservationController).
     */
    private function resolveRecord(Request $request, Child $child): ?EducationRecord
    {
        $user = $request->user();

        return $user->canBypassRls()
            ? $child->educationRecord()->first()
            : $child->educationRecord()->where('institution_id', $user->institution_id)->first();
    }
}
