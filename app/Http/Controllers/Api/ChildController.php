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
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Child::class);

        $user  = $request->user();
        $query = Child::query();

        if ($user->canBypassRls()) {
            // Admin y coordinador ven todos los niños con sus registros de ambos dominios
            $query->with(['educationRecord', 'healthRecord']);
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

        $children = $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20);

        return ChildResource::collection($children);
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
            ]);
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

        $child = Child::create([
            ...$data,
            'created_by' => $request->user()->id,
        ]);

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
