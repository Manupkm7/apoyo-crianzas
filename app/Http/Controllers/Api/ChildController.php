<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChildRequest;
use App\Http\Requests\UpdateChildRequest;
use App\Http\Resources\ChildResource;
use App\Models\Child;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * ChildController — ABM de niños registrados en el sistema.
 *
 * Un niño es el registro central compartido entre todos los módulos.
 * Cada institución agrega su propia información específica (educativa o de salud)
 * a través de los endpoints de /education-record y /health-record.
 *
 * ¿Quién ve qué?
 * - Admin/coordinador: todos los niños, con ambos registros incluidos.
 * - Institución educativa: solo los niños con registro educativo en su institución.
 * - Institución de salud: solo los niños con registro de salud en su institución.
 *
 * Manejo de duplicados por DNI:
 * Si al crear un niño se provee DNI y ya existe otro con ese DNI, el sistema
 * devuelve un 409 Conflict con los datos del niño existente para que el frontend
 * pueda redirigir al perfil correcto en lugar de crear un duplicado.
 */
class ChildController extends Controller
{
    /**
     * Devuelve el listado paginado de niños.
     *
     * El filtrado por institución se aplica automáticamente según el tipo de usuario:
     * - Admin/coordinador: ven todos los niños.
     * - Institución educativa: ven solo sus niños (los que tienen registro educativo en su institución).
     * - Institución de salud: ven solo sus niños (los que tienen registro de salud en su institución).
     *
     * Filtros opcionales por query string (usados por la tabla de niños del dashboard):
     * - search: nombre/apellido (coincidencia parcial) o DNI (coincidencia exacta —
     *   el DNI está cifrado, solo se puede comparar contra su hash).
     * - has_education / has_health: '1' o '0' — filtra por si tiene o no ese registro cargado.
     *   Útil porque cada tipo de institución carga un subconjunto distinto de niños.
     * - alert: '1' — solo niños con alguna alerta (ver ChildResource::computeAlerts).
     * - per_page: tamaño de página (máx. 100, default 20).
     *
     * La respuesta incluye `alerts_count`: cuántos niños del resultado filtrado
     * (antes de aplicar el propio filtro `alert`) tienen alguna alerta.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Child::class);

        $user  = $request->user();
        $query = Child::query();

        if ($user->canBypassRls()) {
            // Admin y coordinador ven todos los niños con sus registros de ambos dominios,
            // más nacimiento/defunción (exclusivos de admin/coordinador — ver BirthRecordPolicy)
            $query->with(['educationRecord', 'healthRecord', 'birthRecord', 'deathRecord']);
        } elseif ($user->institutionType() === 'educacion') {
            // La institución educativa ve solo niños con registro en su institución,
            // e incluye únicamente el registro educativo (no pueden ver datos de salud)
            $query
                ->whereHas('educationRecord', fn ($q) => $q->where('institution_id', $user->institution_id))
                ->with(['educationRecord' => fn ($q) => $q->where('institution_id', $user->institution_id)]);
        } elseif ($user->institutionType() === 'salud') {
            // La institución de salud ve solo niños con registro en su institución
            $query
                ->whereHas('healthRecord', fn ($q) => $q->where('institution_id', $user->institution_id))
                ->with(['healthRecord' => fn ($q) => $q->where('institution_id', $user->institution_id)]);
        } else {
            // Otros tipos de institución (desarrollo_social, etc.) no ven niños aún.
            // Cuando se implementen esos módulos, se agregarán sus condiciones aquí.
            $query->whereRaw('1 = 0');
        }

        if ($search = trim((string) $request->query('search', ''))) {
            $dniHash = hash('sha256', $search);
            $query->where(function ($q) use ($search, $dniHash) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('dni_hash', $dniHash);
            });
        }

        if ($request->has('has_education')) {
            $request->boolean('has_education')
                ? $query->whereHas('educationRecord')
                : $query->whereDoesntHave('educationRecord');
        }
        if ($request->has('has_health')) {
            $request->boolean('has_health')
                ? $query->whereHas('healthRecord')
                : $query->whereDoesntHave('healthRecord');
        }

        // Filtros de nivel/grado educativo — para la tabla del dashboard de instituciones
        // educativas (ej. "todos los niños de 4to grado").
        if ($level = $request->query('level')) {
            $query->whereHas('educationRecord', fn ($q) => $q->where('level', $level));
        }
        if ($request->filled('grade')) {
            $query->whereHas('educationRecord', fn ($q) => $q->where('grade', (int) $request->query('grade')));
        }

        // Misma definición de "alerta" que ChildResource::computeAlerts(), acotada
        // a lo que este usuario puede ver de cada niño (mismo criterio de RLS de arriba).
        $alertCondition = function ($q) use ($user) {
            $q->where(function ($inner) use ($user) {
                if ($user->canBypassRls() || $user->institutionType() === 'educacion') {
                    $inner->orWhereHas('educationRecord', function ($eq) use ($user) {
                        if (! $user->canBypassRls()) {
                            $eq->where('institution_id', $user->institution_id);
                        }
                        $eq->where(fn ($w) => $w->where('is_enrolled', false)->orWhere('absences_count', '>', 10));
                    });
                }
                if ($user->canBypassRls() || $user->institutionType() === 'salud') {
                    $inner->orWhereHas('healthRecord', function ($hq) use ($user) {
                        if (! $user->canBypassRls()) {
                            $hq->where('institution_id', $user->institution_id);
                        }
                        $hq->where(fn ($w) => $w->where('healthy_checkup_current', false)->orWhere('vaccines_current', false));
                    });
                }
            });
        };

        // Se cuenta ANTES de aplicar el filtro `alert`, para que el número no cambie
        // según si el usuario activó o no el toggle "solo con alertas".
        $alertsCount = (clone $query)->where($alertCondition)->count();

        if ($request->boolean('alert')) {
            $query->where($alertCondition);
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $children = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($perPage);

        return ChildResource::collection($children)->additional([
            'alerts_count' => $alertsCount,
        ]);
    }

    /**
     * Devuelve el perfil completo de un niño específico.
     *
     * El detalle incluye el DNI (solo visible para admin/coordinador).
     * Los registros de dominio incluidos dependen del tipo de institución del usuario.
     */
    public function show(Request $request, Child $child): ChildResource
    {
        // Primero cargamos las relaciones necesarias para que la Policy pueda verificar
        // si el niño pertenece a la institución del usuario
        $child->load(['educationRecord', 'healthRecord']);

        $this->authorize('view', $child);

        $user = $request->user();

        if ($user->canBypassRls()) {
            // Admin/coordinador: ver todo, incluyendo el nombre de las instituciones
            $child->load([
                'educationRecord.institution',
                'healthRecord.institution',
                'birthRecord.institution',
                'deathRecord.institution',
            ]);
        } else {
            // Para una institución (no admin/coordinador), avisamos si el niño tiene una
            // alerta en el OTRO sector, sin revelar ningún detalle de ese registro —
            // la institución educativa no puede ver datos de salud y viceversa.
            $otherSectorAlert = match ($user->institutionType()) {
                'educacion' => $child->healthRecord()
                    ->where(fn ($q) => $q->where('healthy_checkup_current', false)->orWhere('vaccines_current', false))
                    ->exists(),
                'salud' => $child->educationRecord()
                    ->where(fn ($q) => $q->where('is_enrolled', false)->orWhere('absences_count', '>', 10))
                    ->exists(),
                default => null,
            };
            $child->setAttribute('other_sector_alert', $otherSectorAlert);
        }

        // El DNI solo se incluye en el detalle, no en el listado
        return (new ChildResource($child))->withDni();
    }

    /**
     * Registra un nuevo niño en el sistema.
     *
     * Antes de crear, verifica si ya existe un niño con el mismo DNI (usando el hash).
     * Si existe, devuelve 409 Conflict con el niño existente para evitar duplicados.
     *
     * El DNI se cifra automáticamente antes de guardarse (cast 'encrypted' en el modelo).
     * El dni_hash se calcula aquí en el controlador con SHA-256 del DNI en texto plano.
     */
    public function store(StoreChildRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Verificar duplicado por DNI antes de crear
        if (! empty($data['dni'])) {
            $dniHash     = hash('sha256', $data['dni']);
            $existingChild = Child::where('dni_hash', $dniHash)->first();

            if ($existingChild) {
                // Devolvemos el niño existente con status 409 para que el frontend
                // pueda redirigir al perfil correcto en lugar de crear un duplicado
                return response()->json([
                    'message' => 'Ya existe un niño registrado con ese DNI.',
                    'child'   => new ChildResource($existingChild),
                ], 409);
            }

            $data['dni_hash'] = $dniHash;
        }

        $user  = $request->user();
        $child = Child::create([
            ...$data,
            'created_by' => $user->id,
        ]);

        // Cuando el creador es una institución (no admin ni coordinador),
        // se auto-vincula el niño a esa institución creando un registro mínimo
        // con valores por defecto para que aparezca de inmediato en el listado.
        // La institución puede completar o corregir el registro después.
        if (! $user->canBypassRls() && $user->institution) {
            $institution = $user->institution;

            if ($institution->type === 'educacion') {
                $child->educationRecord()->create([
                    'institution_id' => $institution->id,
                    'school_name'    => $institution->name,
                    'is_enrolled'    => true,
                    'absences_count' => 0,
                    'created_by'     => $user->id,
                ]);
            } elseif ($institution->type === 'salud') {
                $child->healthRecord()->create([
                    'institution_id'          => $institution->id,
                    'health_center_name'      => $institution->name,
                    'healthy_checkup_current' => true,
                    'vaccines_current'        => true,
                    'created_by'              => $user->id,
                ]);
            }
        }

        return (new ChildResource($child))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Actualiza los datos base de un niño (nombre, fecha de nacimiento, notas).
     *
     * Si se actualiza el DNI, recalcula el hash para mantener la consistencia.
     * La autorización verifica que el usuario tenga vínculo con este niño.
     */
    public function update(UpdateChildRequest $request, Child $child): ChildResource
    {
        // Cargamos relaciones para que la Policy pueda verificar el vínculo
        $child->load(['educationRecord', 'healthRecord']);

        $this->authorize('update', $child);

        $data = $request->validated();

        // Si se cambia el DNI, actualizamos también el hash
        if (array_key_exists('dni', $data)) {
            $data['dni_hash'] = ! empty($data['dni'])
                ? hash('sha256', $data['dni'])
                : null;
        }

        $child->update([
            ...$data,
            'updated_by' => $request->user()->id,
        ]);

        return new ChildResource($child);
    }

    /**
     * Da de baja (soft delete) un registro de niño.
     *
     * Solo el administrador puede hacer esto.
     * Los datos históricos (registros de salud, educación) NO se eliminan.
     */
    public function destroy(Request $request, Child $child): JsonResponse
    {
        $this->authorize('delete', $child);

        $child->update(['updated_by' => $request->user()->id]);
        $child->delete();

        return response()->json([
            'message' => 'Registro de niño desactivado. Los datos históricos se conservan.',
        ]);
    }
}
