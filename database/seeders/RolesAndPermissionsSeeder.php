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

        // Permisos del sistema.
        // Cada permiso controla una capacidad específica dentro del sistema.
        // El tipo de institución (salud, educacion, etc.) se verifica en las Policies,
        // no en los permisos, para mantener la estructura flexible.
        $permissions = [
            'usuarios.gestionar',        // Solo admin: crear/editar/desactivar cualquier usuario
            'instituciones.gestionar',   // Solo admin: ABM de instituciones
            'representantes.gestionar',  // Responsable de institución: gestionar sus representantes
            'reportes.ver',              // Coordinador: acceso de lectura global al sistema
            'ninos.gestionar',           // Instituciones y admin: ABM de niños y sus registros de dominio
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
            'ninos.gestionar',
        ]);

        // -------------------------------------------------------------------------
        // coordinador — visibilidad global, sin capacidad de gestión
        // Puede ver instituciones y reportes, pero no crear ni modificar nada.
        // -------------------------------------------------------------------------
        $coordinador = Role::firstOrCreate(['name' => 'coordinador', 'guard_name' => 'sanctum']);
        $coordinador->syncPermissions([
            'reportes.ver',
            // El coordinador tiene visibilidad global pero no puede crear/editar niños.
            // La Policy de Child permite viewAny a quien tenga 'reportes.ver'.
        ]);

        // -------------------------------------------------------------------------
        // institucion — UN único responsable por institución (DB-enforced)
        // Gestiona a sus propios representantes.
        // -------------------------------------------------------------------------
        $institucion = Role::firstOrCreate(['name' => 'institucion', 'guard_name' => 'sanctum']);
        $institucion->syncPermissions([
            'representantes.gestionar',
            'ninos.gestionar', // El responsable puede registrar y gestionar niños de su institución
        ]);

        // -------------------------------------------------------------------------
        // representante — personal operativo de la institución
        // Rango menor que 'institucion'. Sin permisos de gestión por ahora.
        // -------------------------------------------------------------------------
        $representante = Role::firstOrCreate(['name' => 'representante', 'guard_name' => 'sanctum']);
        $representante->syncPermissions([
            'ninos.gestionar', // El representante también puede registrar y gestionar niños
        ]);

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
