<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationRecord;
use App\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EducationDashboardController — Resumen por nivel/grado para el dashboard
 * de una institución educativa: cuántos niños hay en cada grado y cuántos
 * de ellos tienen alguna alerta (mismo criterio que ChildResource::computeAlerts
 * para el dominio educativo: no escolarizado o inasistencias > 10).
 */
class EducationDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        // Admin/coordinador pueden pedir el resumen de una institución puntual.
        // El resto siempre ve el resumen de su propia institución.
        $institutionId = $user->canBypassRls()
            ? $request->query('institution_id', $user->institution_id)
            : $user->institution_id;

        if (! $institutionId) {
            return response()->json(['message' => 'Se requiere una institución.'], 400);
        }

        $institution = Institution::findOrFail($institutionId);

        $this->authorize('view', $institution);

        if ($institution->type !== 'educacion') {
            return response()->json(['message' => 'Esta institución no es de tipo educación.'], 422);
        }

        $records = EducationRecord::where('institution_id', $institution->id)
            ->get(['level', 'grade', 'is_enrolled', 'absences_count']);

        $levels = [];
        foreach ($institution->educationLevelDefinitions() as $levelKey => $def) {
            $grades = [];
            for ($grade = 1; $grade <= $def['max_grade']; $grade++) {
                $inGrade = $records->where('level', $levelKey)->where('grade', $grade);
                $alerts  = $inGrade->filter(
                    fn ($r) => ! $r->is_enrolled || $r->absences_count > 10
                )->count();

                $grades[] = [
                    'grade'        => $grade,
                    'label'        => EducationRecord::gradeLabel($levelKey, $grade),
                    'count'        => $inGrade->count(),
                    'alerts_count' => $alerts,
                ];
            }

            $levels[] = [
                'level' => $levelKey,
                'label' => $def['label'],
                'grades' => $grades,
            ];
        }

        return response()->json([
            'institution'      => ['id' => $institution->id, 'name' => $institution->name],
            'levels'           => $levels,
            // Registros sin nivel/grado asignado todavía (datos históricos o incompletos)
            'unassigned_count' => $records->whereNull('level')->count(),
            'total'            => $records->count(),
        ]);
    }
}
