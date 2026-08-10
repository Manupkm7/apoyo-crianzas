<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * RoleController — CRUD de roles del sistema.
 *
 * Solo el administrador puede gestionar roles.
 * Los 4 roles del sistema (admin, coordinador, institucion, representante)
 * no pueden eliminarse, pero sí editarse sus permisos.
 * Los roles personalizados pueden crearse, editarse y eliminarse
 * siempre que no tengan usuarios asignados.
 */
class RoleController extends Controller
{
    private const BUILT_IN = ['admin', 'coordinador', 'institucion', 'representante'];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Solo el administrador puede gestionar roles.');

        $roles = Role::where('guard_name', 'sanctum')
            ->with('permissions')
            ->get()
            ->map(fn (Role $role) => [
                'id'          => $role->id,
                'name'        => $role->name,
                'is_built_in' => in_array($role->name, self::BUILT_IN),
                'users_count' => $role->users()->count(),
                'permissions' => $role->permissions->pluck('name')->values(),
            ]);

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'name'          => [
                'required', 'string', 'max:50',
                'unique:roles,name',
                'regex:/^[a-z][a-z0-9_]*$/',
            ],
            'permissions'   => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ], [
            'name.regex'  => 'El nombre solo puede tener letras minúsculas, números y guiones bajos, y debe comenzar con letra.',
            'name.unique' => 'Ya existe un rol con ese nombre.',
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'sanctum']);

        if (! empty($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'data' => [
                'id'          => $role->id,
                'name'        => $role->name,
                'is_built_in' => false,
                'users_count' => 0,
                'permissions' => $role->permissions->pluck('name')->values(),
            ],
        ], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'permissions'   => ['required', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role->syncPermissions($data['permissions']);
        $role->load('permissions');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'data' => [
                'id'          => $role->id,
                'name'        => $role->name,
                'is_built_in' => in_array($role->name, self::BUILT_IN),
                'users_count' => $role->users()->count(),
                'permissions' => $role->permissions->pluck('name')->values(),
            ],
        ]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        if (in_array($role->name, self::BUILT_IN)) {
            abort(422, 'Los roles del sistema no pueden eliminarse.');
        }

        if ($role->users()->count() > 0) {
            abort(422, 'No se puede eliminar un rol con usuarios asignados. Reasignales un rol primero.');
        }

        $role->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'Rol eliminado correctamente.']);
    }
}
