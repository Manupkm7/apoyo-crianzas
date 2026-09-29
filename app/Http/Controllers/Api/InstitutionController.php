<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInstitutionRequest;
use App\Http\Requests\UpdateInstitutionRequest;
use App\Http\Resources\InstitutionResource;
use App\Models\Institution;
use App\Support\LocalityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

/**
 * InstitutionController — ABM (Alta, Baja, Modificación) de instituciones.
 *
 * Este controlador maneja todas las operaciones sobre instituciones municipales:
 * crear una nueva institución, ver su información, modificarla o desactivarla.
 *
 * ¿Quién puede usar cada endpoint?
 * - Listar y ver: admin, coordinador, y cada institución su propia ficha.
 * - Crear, modificar, desactivar: solo administrador.
 *
 * Las instituciones NUNCA se eliminan físicamente. Se marcan como inactivas.
 * Esto preserva el historial de datos vinculados a esa institución.
 */
class InstitutionController extends Controller
{
    /**
     * Devuelve el listado paginado de instituciones.
     *
     * El admin y coordinador ven todas.
     * El responsable o representante solo ve su propia institución.
     *
     * Se incluye el conteo de usuarios activos de cada institución.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Institution::class);

        $institutions = Institution::query()
            ->with('locality.department.province')
            ->withCount([
                // Cuenta solo los usuarios activos (no los desactivados ni eliminados)
                'users' => fn ($q) => $q->where('is_active', true),
            ])
            // Si el usuario NO puede bypassear RLS (admin/coordinador),
            // filtramos para que solo vea su propia institución
            ->when(
                ! $request->user()->canBypassRls(),
                fn ($q) => $q->where('id', $request->user()->institution_id)
            )
            // Ámbito global del sidebar (solo admin/coordinador). Los desplegables
            // de formularios lo desactivan desde el frontend (header 'none').
            ->when(
                LocalityScope::fromRequest($request),
                fn ($q, string $localityKey) => LocalityScope::applyToInstitutions($q, $localityKey)
            )
            ->orderBy('name')
            // ?per_page= para los desplegables que necesitan todas (ej. elegir el
            // efector de una prestación); el listado sigue paginando de a 20.
            ->paginate(max(1, min((int) $request->query('per_page', 20), 500)));

        return InstitutionResource::collection($institutions);
    }

    /**
     * Devuelve el detalle de una institución específica.
     *
     * Route model binding resuelve automáticamente el UUID en la URL
     * al modelo Institution correspondiente.
     */
    public function show(Institution $institution): InstitutionResource
    {
        $this->authorize('view', $institution);

        // Cargamos el conteo de usuarios y la cadena de jurisdicción para el detalle
        $institution->load(['locality.department.province', 'programArea']);
        $institution->loadCount([
            'users' => fn ($q) => $q->where('is_active', true),
        ]);

        return (new InstitutionResource($institution))->withArticulations();
    }

    /**
     * Crea una nueva institución.
     *
     * Solo accesible para el administrador.
     * Los datos son validados previamente por StoreInstitutionRequest.
     *
     * Se registra quién creó la institución (auditoría).
     */
    public function store(StoreInstitutionRequest $request): JsonResponse
    {
        // La autorización ya fue verificada en StoreInstitutionRequest::authorize()
        // Pero también podríamos llamar $this->authorize('create', Institution::class)

        // Contraseña inicial para el login institucional (provincia → departamento →
        // localidad → sector → institución). Se genera al azar y se devuelve UNA
        // sola vez en esta respuesta: no queda recuperable después.
        $plainPassword = Str::password(16);

        $data = $request->validated();
        $articulationIds = $data['articulation_ids'] ?? null;
        unset($data['articulation_ids']);

        $institution = Institution::create([
            ...$data,
            'password'              => $plainPassword,
            'password_must_change'  => true,
            // Registramos el ID del admin que creó esta institución
            'created_by' => $request->user()->id,
        ]);

        if ($articulationIds !== null) {
            $institution->syncArticulations($articulationIds, $request->user()->auditId());
        }

        // refresh(): 'code' (ID_EFECTOR) lo genera la base con su secuencia.
        $institution->refresh()->load(['locality.department.province', 'programArea']);

        return (new InstitutionResource($institution))
            ->withArticulations()
            ->additional(['initial_password' => $plainPassword])
            ->response()
            ->setStatusCode(201); // 201 Created
    }

    /**
     * Genera una nueva contraseña aleatoria para el login institucional y la
     * devuelve en texto plano UNA sola vez (mismo criterio que la creación).
     * Invalida los tokens de sesión institucionales vigentes.
     */
    public function resetPassword(Request $request, Institution $institution): JsonResponse
    {
        $this->authorize('update', $institution);

        if (! $request->user()->can('instituciones.gestionar')) {
            abort(403, 'Solo el administrador puede resetear la contraseña institucional.');
        }

        $plainPassword = Str::password(16);

        $institution->update([
            'password'              => $plainPassword,
            'password_must_change'  => true,
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'updated_by'            => $request->user()->id,
        ]);

        $institution->tokens()->delete();

        return response()->json([
            'message'          => 'Contraseña institucional reseteada correctamente.',
            'initial_password' => $plainPassword,
        ]);
    }

    /**
     * Actualiza los datos de una institución existente.
     *
     * Es una actualización parcial (PATCH): solo se modifican los campos enviados.
     * Solo accesible para el administrador.
     *
     * Se registra quién hizo la modificación (auditoría).
     */
    public function update(UpdateInstitutionRequest $request, Institution $institution): InstitutionResource
    {
        // La autorización se verifica en dos capas:
        // 1. UpdateInstitutionRequest::authorize() — acceso rápido antes de validar campos
        // 2. InstitutionPolicy::update() — verificación por modelo (quién puede editar cuál)
        $this->authorize('update', $institution);

        $data = $request->validated();
        $articulationIds = $data['articulation_ids'] ?? null;
        unset($data['articulation_ids']);

        $institution->update([
            ...$data,
            // Registramos el ID del admin que modificó esta institución
            'updated_by' => $request->user()->id,
        ]);

        // Solo si vino en el request (solo admin lo puede mandar): lista completa,
        // se agregan las nuevas y se quitan las que ya no están.
        if ($articulationIds !== null) {
            $institution->syncArticulations($articulationIds, $request->user()->auditId());
        }

        $institution->load(['locality.department.province', 'programArea']);

        return (new InstitutionResource($institution))->withArticulations();
    }

    /**
     * Desactiva una institución (baja lógica, no eliminación física).
     *
     * La institución queda marcada como eliminada (deleted_at) pero sus datos
     * históricos (registros de salud, educación, etc.) permanecen en el sistema.
     *
     * Solo accesible para el administrador.
     *
     * ADVERTENCIA: Si la institución tiene usuarios activos, estos quedarán sin
     * institución asignada. Se recomienda desactivar primero a los usuarios.
     */
    public function destroy(Request $request, Institution $institution): JsonResponse
    {
        $this->authorize('delete', $institution);

        // Registramos quién hizo la baja antes de marcar como eliminada
        $institution->update(['updated_by' => $request->user()->id]);
        $institution->delete(); // Soft delete — guarda deleted_at, no borra el registro

        return response()->json([
            'message' => 'Institución desactivada correctamente. Los registros históricos se conservan.',
        ]);
    }
}
