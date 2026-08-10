<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * PermissionController — CRUD de permisos del sistema.
 *
 * Solo el administrador puede gestionar permisos.
 * Los permisos en uso (asignados a roles o usuarios) no pueden eliminarse.
 * El nombre del permiso sigue el formato "grupo.accion" (ej: reportes.exportar).
 */
class PermissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Solo el administrador puede gestionar permisos.');

        $permissions = Permission::where('guard_name', 'sanctum')
            ->orderBy('name')
            ->get()
            ->map(fn (Permission $p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'roles_count' => $p->roles()->count(),
                'users_count' => $p->users()->count(),
            ]);

        return response()->json(['data' => $permissions]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                'unique:permissions,name',
                'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/',
            ],
        ], [
            'name.regex'  => 'El permiso debe seguir el formato "grupo.accion" (ej: reportes.exportar). Solo letras minúsculas, números y guiones bajos.',
            'name.unique' => 'Ya existe un permiso con ese nombre.',
        ]);

        $permission = Permission::create(['name' => $data['name'], 'guard_name' => 'sanctum']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json([
            'data' => [
                'id'          => $permission->id,
                'name'        => $permission->name,
                'roles_count' => 0,
                'users_count' => 0,
            ],
        ], 201);
    }

    public function destroy(Request $request, Permission $permission): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        $rolesCount = $permission->roles()->count();
        $usersCount = $permission->users()->count();

        if ($rolesCount > 0) {
            abort(422, "No se puede eliminar: el permiso está asignado a {$rolesCount} rol(es). Removelo de los roles primero.");
        }

        if ($usersCount > 0) {
            abort(422, "No se puede eliminar: el permiso está asignado directamente a {$usersCount} usuario(s).");
        }

        $permission->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return response()->json(['message' => 'Permiso eliminado correctamente.']);
    }
}
