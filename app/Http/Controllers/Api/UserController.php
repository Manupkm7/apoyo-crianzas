<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Activitylog\Models\Activity;

/**
 * UserController — ABM (Alta, Baja, Modificación) de usuarios.
 *
 * Este controlador gestiona la creación y administración de usuarios del sistema.
 * Hay una regla de negocio crítica aquí: cada institución puede tener
 * UN SOLO usuario con rol 'institucion' (el responsable). El sistema lo
 * impone a dos niveles: validación en este controlador + índice único en la BD.
 *
 * Resumen de quién gestiona a quién:
 * - Admin: gestiona todos los usuarios (cualquier rol, cualquier institución).
 * - Responsable de institución: gestiona solo sus representantes.
 * - Representante: no gestiona usuarios (solo ve su propio perfil).
 */
class UserController extends Controller
{
    /**
     * Devuelve el listado paginado de usuarios.
     *
     * - Admin: ve todos los usuarios del sistema.
     * - Responsable de institución: ve solo los representantes de su institución.
     *
     * No se incluyen los permisos en el listado (solo en el detalle)
     * para mantener las respuestas livianas.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $authUser = $request->user();

        $users = User::query()
            ->with(['institution', 'roles'])
            // Si el usuario no es admin, filtramos para mostrar solo
            // los representantes de su propia institución
            ->when(
                ! $authUser->can('usuarios.gestionar'),
                fn ($q) => $q
                    ->where('institution_id', $authUser->institution_id)
                    ->whereHas('roles', fn ($r) => $r->where('name', 'representante'))
            )
            ->orderBy('name')
            ->paginate(20);

        return UserResource::collection($users);
    }

    /**
     * Devuelve el detalle completo de un usuario, incluyendo sus permisos.
     *
     * Cualquier usuario puede ver su propio perfil.
     * El admin ve a cualquier usuario.
     * El responsable ve a sus representantes.
     */
    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        $user->load(['institution', 'roles']);

        return new UserResource($user);
    }

    /**
     * Crea un nuevo usuario en el sistema.
     *
     * LÓGICA ESPECIAL PARA ROL 'institucion':
     * Si el rol asignado es 'institucion', este usuario se convierte en el
     * responsable principal de la institución. El sistema verifica que no
     * exista otro responsable activo para esa institución. Si ya existe uno,
     * devuelve un error y pide que se lo desactive primero.
     *
     * También se registra quién creó el usuario (auditoría).
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        $role = $data['role'];

        // Verificación especial para el rol 'institucion':
        // Debe ser el único responsable activo de su institución.
        // Hacemos la verificación ANTES de crear el usuario para dar
        // un mensaje claro, sin depender del error de la base de datos.
        if ($role === 'institucion') {
            $this->abortIfInstitutionAlreadyHasHead($data['institution_id']);
        }

        $user = User::create([
            'name'                => $data['name'],
            'email'               => $data['email'],
            'password'            => $data['password'],
            'institution_id'      => $data['institution_id'] ?? null,
            'is_active'           => $data['is_active'] ?? true,
            // Solo los usuarios con rol 'institucion' son marcados como responsables
            'is_institution_head' => $role === 'institucion',
            'created_by'          => $request->user()->id,
        ]);

        // Asignamos el rol al usuario recién creado
        $user->assignRole($role);

        // Cargamos las relaciones para incluirlas en la respuesta
        $user->load(['institution', 'roles']);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201); // 201 Created
    }

    /**
     * Actualiza los datos de un usuario existente.
     *
     * Es una actualización parcial (PATCH): solo se modifican los campos enviados.
     *
     * CAMBIO DE ROL:
     * Si el admin cambia el rol de un usuario:
     * - Si lo saca del rol 'institucion': se limpia el flag is_institution_head.
     * - Si lo pone en el rol 'institucion': se verifica unicidad y se activa el flag.
     * - Un usuario tiene exactamente un rol en todo momento.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);

        $data = $request->validated();

        // Si se está cambiando el rol, ejecutamos la lógica especial de cambio de rol
        if (isset($data['role']) && ! $user->hasRole($data['role'])) {
            $institutionId = $data['institution_id'] ?? $user->institution_id;
            $this->handleRoleChange($user, $data['role'], $institutionId);
        }

        // Actualizamos los campos de datos del usuario
        // array_filter elimina nulls para no sobreescribir datos con nada
        $updateData = array_filter([
            'name'           => $data['name'] ?? null,
            'email'          => $data['email'] ?? null,
            'institution_id' => $data['institution_id'] ?? null,
            'is_active'      => $data['is_active'] ?? null,
            'updated_by'     => $request->user()->id,
        ], fn ($v) => $v !== null);

        // La contraseña se trata aparte porque array_filter eliminaría valores falsy
        if (isset($data['password'])) {
            $updateData['password'] = $data['password'];
        }

        $user->update($updateData);

        $user->load(['institution', 'roles']);

        return new UserResource($user);
    }

    /**
     * Desactiva un usuario (baja lógica, no eliminación física).
     *
     * El usuario queda marcado como eliminado (deleted_at) y no puede
     * iniciar sesión. Sus datos históricos se conservan en el sistema.
     *
     * Si el usuario era responsable de una institución (is_institution_head),
     * se libera ese "cargo" para que pueda asignarse a otro usuario.
     *
     * RESTRICCIÓN: nadie puede desactivarse a sí mismo.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('delete', $user);

        // Si el usuario era responsable de institución, liberamos el cargo
        // para que la institución pueda asignar un nuevo responsable
        if ($user->is_institution_head) {
            $user->update([
                'is_institution_head' => false,
                'updated_by'          => $request->user()->id,
            ]);
        }

        $user->delete(); // Soft delete — guarda deleted_at, no borra el registro

        return response()->json([
            'message' => 'Usuario desactivado correctamente.',
        ]);
    }

    /**
     * Devuelve el historial de actividad de un usuario (qué acciones realizó en el sistema).
     *
     * Sirve para que los responsables de institución puedan auditar lo que hicieron
     * sus representantes: qué niños registraron, qué datos cargaron o modificaron.
     *
     * ¿Quién puede verlo?
     * - Admin y coordinador: pueden ver la actividad de cualquier usuario.
     * - Responsable de institución: puede ver la actividad de sus propios representantes.
     *
     * La autorización reutiliza la política `view` de UserPolicy:
     * si podés ver el usuario, podés ver su actividad.
     */
    public function activityLog(Request $request, User $user): AnonymousResourceCollection
    {
        $this->authorize('view', $user);

        $logs = Activity::query()
            ->where('causer_type', User::class)
            ->where('causer_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate(30);

        return ActivityResource::collection($logs);
    }

    // =========================================================================
    // Métodos privados de apoyo
    // =========================================================================

    /**
     * Verifica que la institución no tenga ya un responsable activo.
     *
     * Si ya tiene uno, aborta con un error 422 y un mensaje claro.
     * Esta verificación es a nivel de aplicación; la base de datos tiene
     * un índice único adicional como segunda línea de defensa.
     *
     * @param string $institutionId UUID de la institución a verificar
     */
    private function abortIfInstitutionAlreadyHasHead(string $institutionId): void
    {
        $alreadyHasHead = User::where('institution_id', $institutionId)
            ->where('is_institution_head', true)
            ->whereNull('deleted_at') // Ignoramos los desactivados
            ->exists();

        if ($alreadyHasHead) {
            abort(422, 'Esta institución ya tiene un usuario responsable activo. '
                . 'Para asignar uno nuevo, primero desactive al responsable actual.');
        }
    }

    /**
     * Maneja el cambio de rol de un usuario.
     *
     * Cuando se cambia el rol de un usuario, hay que tener en cuenta:
     * 1. Si deja de ser responsable (rol 'institucion'), se libera el "cargo".
     * 2. Si pasa a ser responsable, se verifica unicidad y se activa el flag.
     * 3. Se reemplazan todos los roles por el nuevo (un usuario = un rol).
     *
     * @param User   $user          El usuario al que se le cambia el rol
     * @param string $newRole       El nuevo rol a asignar
     * @param string $institutionId UUID de la institución (para verificar unicidad)
     */
    private function handleRoleChange(User $user, string $newRole, string $institutionId): void
    {
        // Si el usuario era responsable y pasa a otro rol, liberamos el cargo
        if ($user->is_institution_head && $newRole !== 'institucion') {
            $user->update(['is_institution_head' => false]);
        }

        // Si el nuevo rol es 'institucion', verificamos que no haya otro responsable
        if ($newRole === 'institucion') {
            // Excluimos al usuario actual de la verificación (para evitar falsos positivos
            // si se "reasigna" el mismo rol que ya tenía)
            $alreadyHasHead = User::where('institution_id', $institutionId)
                ->where('is_institution_head', true)
                ->where('id', '!=', $user->id)
                ->whereNull('deleted_at')
                ->exists();

            if ($alreadyHasHead) {
                abort(422, 'Esta institución ya tiene un usuario responsable activo. '
                    . 'Para reasignar el cargo, primero desactive al responsable actual.');
            }

            // Activamos el flag de responsable en el usuario
            $user->update(['is_institution_head' => true]);
        }

        // Reemplazamos todos los roles por el nuevo (syncRoles elimina los anteriores)
        $user->syncRoles([$newRole]);
    }
}
