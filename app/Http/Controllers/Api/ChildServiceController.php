<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChildServiceRequest;
use App\Http\Resources\ChildServiceResource;
use App\Models\Child;
use App\Models\ChildService;
use App\Models\Institution;
use App\Models\ServiceType;
use App\Support\LocalityScope;
use App\Support\ServicePeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ChildServiceController — prestaciones por período de un niño (carga manual).
 *
 *   GET    /children/{child}/services             → listar (?year=&period_type=&period_number=)
 *   POST   /children/{child}/services             → cargar una prestación
 *   PATCH  /children/{child}/services/{service}   → corregir una prestación
 *   DELETE /children/{child}/services/{service}   → dar de baja (solo admin)
 *
 * La carga masiva (source 'services') entra por ImportController y termina en
 * la misma tabla. Autorización: ChildServicePolicy. Una institución solo ve y
 * carga las prestaciones en las que ella es el efector.
 */
class ChildServiceController extends Controller
{
    /**
     * GET /child-services — grilla de prestaciones de TODOS los niños visibles,
     * una fila por prestación (misma estructura que el archivo: Fecha, Niño, Nro,
     * Sector, Efector, Nombre prestación, Observaciones, Alerta).
     *
     * Filtros: ?period=TRIM12026 · ?search=nombre/apellido · ?alert=1
     * RLS: admin/coordinador ven todas; una institución solo las que ELLA efectuó
     * y de niños que ya puede ver (mismo criterio que ChildController::index).
     * Devuelve además 'periods' (períodos disponibles, para el select).
     */
    public function all(Request $request): JsonResponse
    {
        $user  = $request->user();
        // whereHas('child') deja afuera a los niños dados de baja (soft delete)
        $query = ChildService::query()->whereHas('child');

        if (! $user->canBypassRls()) {
            if (! $user->isInstitutionalUser()) {
                abort(403);
            }

            $recordRelation = match ($user->institutionType()) {
                'educacion' => 'child.educationRecord',
                'salud'     => 'child.healthRecord',
                default     => null,
            };

            $recordRelation
                ? $query->where('child_services.institution_id', $user->institution_id)
                    ->whereHas($recordRelation, fn ($q) => $q->where('institution_id', $user->institution_id))
                : $query->whereRaw('1 = 0');
        }

        // Ámbito global del sidebar (solo admin/coordinador): prestaciones de los
        // niños de esa localidad — mismo criterio que ChildController::index.
        if ($localityKey = LocalityScope::fromRequest($request)) {
            $query->whereHas('child', fn ($q) => LocalityScope::applyToChildren($q, $localityKey));
        }

        // Períodos disponibles ANTES de filtrar por período/búsqueda (para el select).
        $periods = (clone $query)
            ->select('year', 'period_type', 'period_number')
            ->selectRaw('MAX(period_start) AS period_start')
            ->groupBy('year', 'period_type', 'period_number')
            ->orderByDesc('period_start')
            ->get()
            ->map(fn ($p) => [
                'code'  => ServicePeriod::code($p->period_type, (int) $p->period_number, (int) $p->year),
                'label' => ServicePeriod::label($p->period_type, (int) $p->period_number, (int) $p->year),
            ])
            ->values();

        if ($request->filled('period')) {
            $period = ServicePeriod::parse((string) $request->query('period'));
            $period
                ? $query->where('child_services.year', $period['year'])
                    ->where('child_services.period_type', $period['type'])
                    ->where('child_services.period_number', $period['number'])
                : $query->whereRaw('1 = 0');
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $terms = preg_split('/\s+/', $search);
            $query->whereHas('child', function ($q) use ($terms) {
                // Cada palabra tiene que aparecer en el nombre o en el apellido
                // ("maria perez" encuentra a María Perez).
                foreach ($terms as $term) {
                    $q->where(fn ($w) => $w
                        ->where('first_name', 'ilike', "%{$term}%")
                        ->orWhere('last_name', 'ilike', "%{$term}%"));
                }
            });
        }

        if ($request->boolean('alert')) {
            $query->where('child_services.has_alert', true);
        }

        $perPage = (int) $request->query('per_page', 25);
        $perPage = $perPage > 0 ? min($perPage, 100) : 25;

        $services = $query
            ->with(['child', 'serviceType', 'institution'])
            // join solo para ordenar por apellido/nombre; excluye niños dados de baja
            ->join('children', 'children.id', '=', 'child_services.child_id')
            ->whereNull('children.deleted_at')
            ->select('child_services.*')
            ->orderByDesc('child_services.period_start')
            ->orderBy('children.last_name')
            ->orderBy('children.first_name')
            ->orderBy('child_services.service_number')
            ->paginate($perPage);

        return ChildServiceResource::collection($services)
            ->additional(['periods' => $periods])
            ->response();
    }

    public function index(Request $request, Child $child): JsonResponse
    {
        $child->load(['educationRecord', 'healthRecord']);
        $this->authorize('viewAny', [ChildService::class, $child]);

        $user = $request->user();

        $services = $child->services()
            ->with(['serviceType', 'institution'])
            ->when(! $user->canBypassRls(), fn ($q) => $q->where('institution_id', $user->institution_id))
            ->when($request->filled('year'), fn ($q) => $q->where('year', (int) $request->query('year')))
            ->when($request->filled('period_type'), fn ($q) => $q->where('period_type', $request->query('period_type')))
            ->when($request->filled('period_number'), fn ($q) => $q->where('period_number', (int) $request->query('period_number')))
            ->orderByDesc('period_start')
            ->orderBy('service_number')
            ->get();

        return response()->json(ChildServiceResource::collection($services));
    }

    public function store(ChildServiceRequest $request, Child $child): JsonResponse
    {
        $child->load(['educationRecord', 'healthRecord']);
        $this->authorize('create', [ChildService::class, $child]);

        $user = $request->user();
        $data = $request->validated();

        $data['institution_id'] = $user->hasRole('admin') ? $data['institution_id'] : $user->institution_id;

        if ($error = $this->validateRelations($data)) {
            return response()->json(['message' => $error], 422);
        }

        $data['sector'] ??= ServiceType::find($data['service_type_id'])->sector;

        if ($this->duplicateExists($child, $data)) {
            return response()->json([
                'message' => 'Esa prestación ya está cargada para este efector en ese período. Editá la existente.',
            ], 409);
        }

        $service = $child->services()->create([
            ...$data,
            'created_by' => $user->auditId(),
        ]);

        return response()->json(new ChildServiceResource($service->load(['serviceType', 'institution'])), 201);
    }

    public function update(ChildServiceRequest $request, Child $child, ChildService $service): JsonResponse
    {
        abort_unless($service->child_id === $child->id, 404);
        $this->authorize('update', $service);

        $data   = $request->validated();
        $merged = [...$service->only(['service_type_id', 'institution_id', 'year', 'period_type', 'period_number']), ...$data];

        if (! ServicePeriod::isValid($merged['period_type'], (int) $merged['period_number'], (int) $merged['year'])) {
            return response()->json(['message' => 'El período no es válido.'], 422);
        }

        // Solo se revalida lo que cambia: corregir la observación de una prestación
        // cuyo tipo se desactivó después en el catálogo tiene que seguir andando.
        $error = $this->validateRelations(
            $merged,
            checkType: $merged['service_type_id'] !== $service->service_type_id,
            checkInstitution: $merged['institution_id'] !== $service->institution_id,
        );
        if ($error) {
            return response()->json(['message' => $error], 422);
        }

        if ($this->duplicateExists($child, $merged, $service->id)) {
            return response()->json([
                'message' => 'Ya hay otra prestación igual cargada para este efector en ese período.',
            ], 409);
        }

        $service->update([
            ...$data,
            'updated_by' => $request->user()->auditId(),
        ]);

        return response()->json(new ChildServiceResource($service->load(['serviceType', 'institution'])));
    }

    public function destroy(Request $request, Child $child, ChildService $service): JsonResponse
    {
        abort_unless($service->child_id === $child->id, 404);
        $this->authorize('delete', $service);

        $service->update(['updated_by' => $request->user()->auditId()]);
        $service->delete();

        return response()->json(['message' => 'Prestación dada de baja.']);
    }

    /**
     * Prestación activa en el catálogo + efector existente y activo. Devuelve el
     * mensaje de error, o null si está todo bien.
     */
    private function validateRelations(array $data, bool $checkType = true, bool $checkInstitution = true): ?string
    {
        if ($checkType) {
            $type = ServiceType::find($data['service_type_id']);
            if (! $type || ! $type->is_active) {
                return 'La prestación elegida no está activa en el catálogo.';
            }
        }

        if ($checkInstitution) {
            $institution = Institution::find($data['institution_id']);
            if (! $institution || ! $institution->is_active) {
                return 'El efector elegido no existe o está inactivo.';
            }
        }

        return null;
    }

    private function duplicateExists(Child $child, array $data, ?string $exceptId = null): bool
    {
        return $child->services()
            ->where('service_type_id', $data['service_type_id'])
            ->where('institution_id', $data['institution_id'])
            ->where('year', (int) $data['year'])
            ->where('period_type', $data['period_type'])
            ->where('period_number', (int) $data['period_number'])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }
}
