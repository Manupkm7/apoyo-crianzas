<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Permisos actuales del sistema.
        // A medida que se agreguen módulos (familias, salud, educación, etc.)
        // se agregarán los permisos correspondientes aquí.
        $permissions = [
            'usuarios.gestionar',        // Solo admin: crear/editar/desactivar cualquier usuario
            'instituciones.gestionar',   // Solo admin: ABM de instituciones
            'representantes.gestionar',  // Responsable de institución: gestionar sus representantes
            'reportes.ver',              // Coordinador: acceso de lectura global al sistema
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }

        // -------------------------------------------------------------------------
        // admin — control total del sistema
        // Puede gestionar usuarios, instituciones y ver todo.
        // -------------------------------------------------------------------------
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'sanctum']);
        $admin->syncPermissions([
            'usuarios.gestionar',
            'instituciones.gestionar',
            'reportes.ver',
        ]);

        // -------------------------------------------------------------------------
        // coordinador — visibilidad global, sin capacidad de gestión
        // Puede ver instituciones y reportes, pero no crear ni modificar nada.
        // -------------------------------------------------------------------------
        $coordinador = Role::firstOrCreate(['name' => 'coordinador', 'guard_name' => 'sanctum']);
        $coordinador->syncPermissions([
            'reportes.ver',
        ]);

        // -------------------------------------------------------------------------
        // institucion — UN único responsable por institución (DB-enforced)
        // Gestiona a sus propios representantes.
        // -------------------------------------------------------------------------
        $institucion = Role::firstOrCreate(['name' => 'institucion', 'guard_name' => 'sanctum']);
        $institucion->syncPermissions([
            'representantes.gestionar',
        ]);

        // -------------------------------------------------------------------------
        // representante — personal operativo de la institución
        // Rango menor que 'institucion'. Sin permisos de gestión por ahora.
        // -------------------------------------------------------------------------
        $representante = Role::firstOrCreate(['name' => 'representante', 'guard_name' => 'sanctum']);
        $representante->syncPermissions([]);

        // -------------------------------------------------------------------------
        // Usuario administrador inicial del sistema
        // -------------------------------------------------------------------------
        $institution = Institution::firstOrCreate(
            ['name' => 'Municipalidad - Administración Central'],
            [
                'type'      => 'otro',
                'is_active' => true,
            ]
        );

        $adminUser = User::firstOrCreate(
            ['email' => 'admin@crianza.local'],
            [
                'name'                => 'Administrador del Sistema',
                'password'            => Hash::make('Crianza2026!Admin#'),
                'institution_id'      => $institution->id,
                'is_active'           => true,
                'is_institution_head' => false, // El admin es global, no es cabeza de institución
            ]
        );

        $adminUser->syncRoles(['admin']);
    }
}
