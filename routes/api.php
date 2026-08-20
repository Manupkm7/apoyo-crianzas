<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BirthRecordController;
use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\DatabaseExportController;
use App\Http\Controllers\Api\DeathRecordController;
use App\Http\Controllers\Api\EducationDashboardController;
use App\Http\Controllers\Api\EducationObservationController;
use App\Http\Controllers\Api\EducationRecordController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\HealthObservationController;
use App\Http\Controllers\Api\HealthRecordController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InstitutionAuthController;
use App\Http\Controllers\Api\InstitutionController;
use App\Http\Controllers\Api\InstitutionDirectoryController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Sistema de Apoyo a la Crianza
|--------------------------------------------------------------------------
| Todas las respuestas son JSON. Autenticación mediante tokens de Sanctum.
| Autorización mediante Policies de Laravel + permisos de Spatie.
|
| Prefijo base: /api/v1/
| El prefijo /api se agrega automáticamente por la configuración de Laravel.
*/

Route::prefix('v1')->group(function () {

    // -------------------------------------------------------------------------
    // Endpoints públicos — solo requieren límite de intentos (throttle)
    // throttle:10,1 = máximo 10 requests por minuto por IP
    // -------------------------------------------------------------------------
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('login', [AuthController::class, 'login']);

        // Login institucional: provincia → departamento → localidad → sector →
        // institución → contraseña. No reemplaza el login de arriba, es un
        // segundo camino de acceso (o el usuario elige loguearse como
        // representante con su cuenta personal, vía /login).
        Route::post('institution-login', [InstitutionAuthController::class, 'login']);
    });

    // -------------------------------------------------------------------------
    // Catálogo geográfico y desplegable de instituciones — públicos, solo
    // lectura, throttle más laxo porque no exponen datos sensibles ni
    // permiten intentos de credenciales.
    // -------------------------------------------------------------------------
    Route::middleware('throttle:30,1')->prefix('geo')->group(function () {
        Route::get('provinces', [GeoController::class, 'provinces']);
        Route::get('provinces/{province}/departments', [GeoController::class, 'departments']);
        Route::get('departments/{department}/localities', [GeoController::class, 'localities']);
        Route::get('sectors', [GeoController::class, 'sectors']);
    });

    Route::middleware('throttle:30,1')->get('institutions/directory', [InstitutionDirectoryController::class, 'index']);

    // -------------------------------------------------------------------------
    // Endpoints autenticados — requieren token de Sanctum válido
    // -------------------------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {

        // Sesión del actor actual (User o Institution, ver AuthController::me)
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me'])->name('me');

        // -----------------------------------------------------------------------
        // ABM de Instituciones
        // - GET    /api/v1/institutions         → listar instituciones
        // - POST   /api/v1/institutions         → crear institución [solo admin]
        // - GET    /api/v1/institutions/{id}    → ver institución
        // - PATCH  /api/v1/institutions/{id}    → modificar institución [solo admin]
        // - DELETE /api/v1/institutions/{id}    → desactivar institución [solo admin]
        // - POST   /api/v1/institutions/{id}/reset-password → nueva password institucional [solo admin]
        // -----------------------------------------------------------------------
        Route::apiResource('institutions', InstitutionController::class);
        Route::post('institutions/{institution}/reset-password', [InstitutionController::class, 'resetPassword']);

        // -----------------------------------------------------------------------
        // ABM de Usuarios
        // - GET    /api/v1/users                   → listar usuarios
        // - POST   /api/v1/users                   → crear usuario
        // - GET    /api/v1/users/{id}              → ver perfil de usuario
        // - PATCH  /api/v1/users/{id}              → modificar usuario
        // - DELETE /api/v1/users/{id}              → desactivar usuario
        // - GET    /api/v1/users/{id}/activity     → actividad del usuario (audit log)
        // -----------------------------------------------------------------------
        Route::apiResource('users', UserController::class);
        Route::get('users/{user}/activity', [UserController::class, 'activityLog']);
        Route::put('users/{user}/permissions', [UserController::class, 'syncDirectPermissions']);

        // -----------------------------------------------------------------------
        // Roles y permisos — solo administrador
        // - GET    /api/v1/roles                       → listar roles con permisos
        // - POST   /api/v1/roles                       → crear rol personalizado
        // - PUT    /api/v1/roles/{role}                → actualizar permisos de un rol
        // - DELETE /api/v1/roles/{role}                → eliminar rol personalizado
        // - GET    /api/v1/permissions                 → listar todos los permisos
        // - POST   /api/v1/permissions                 → crear nuevo permiso
        // - DELETE /api/v1/permissions/{permission}    → eliminar permiso (si no está en uso)
        // -----------------------------------------------------------------------
        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);

        Route::get('permissions', [PermissionController::class, 'index']);
        Route::post('permissions', [PermissionController::class, 'store']);
        Route::delete('permissions/{permission}', [PermissionController::class, 'destroy']);

        // -----------------------------------------------------------------------
        // ABM de Niños
        // - GET    /api/v1/children         → listar niños (filtrado por institución)
        // - POST   /api/v1/children         → registrar nuevo niño
        // - GET    /api/v1/children/{id}    → ver perfil completo del niño
        // - PATCH  /api/v1/children/{id}    → modificar datos del niño
        // - DELETE /api/v1/children/{id}    → dar de baja (solo admin)
        // -----------------------------------------------------------------------
        Route::apiResource('children', ChildController::class);

        // -----------------------------------------------------------------------
        // Dashboard de instituciones educativas
        // - GET /api/v1/education-dashboard[?institution_id=]  → conteo de niños y
        //   alertas por nivel/grado (admin/coordinador pueden pasar institution_id)
        // -----------------------------------------------------------------------
        Route::get('education-dashboard', [EducationDashboardController::class, 'show']);

        // -----------------------------------------------------------------------
        // Descarga de adjuntos de observaciones (PDF) — siempre reverifica el
        // acceso al registro antes de servir el archivo.
        // -----------------------------------------------------------------------
        Route::get('education-observations/{observation}/attachment', [EducationObservationController::class, 'downloadAttachment'])
            ->name('education-observations.attachment');

        Route::get('health-observations/{observation}/attachment', [HealthObservationController::class, 'downloadAttachment'])
            ->name('health-observations.attachment');

        // -----------------------------------------------------------------------
        // Exportación completa de la base de datos — exclusivo del administrador.
        // - GET /api/v1/database-export → descarga un ZIP con todos los datos
        //   del sistema (menos activity_log, ver DatabaseExportController).
        // -----------------------------------------------------------------------
        Route::get('database-export', [DatabaseExportController::class, 'download']);

        // -----------------------------------------------------------------------
        // Importaciones masivas (Registro Civil y Educación)
        // - GET    /api/v1/imports                                 → listar batches
        // - POST   /api/v1/imports                                 → subir archivo (CSV, TXT o Excel) [solo admin]
        // - GET    /api/v1/imports/template?source=&format=        → descargar plantilla (xlsx|csv|txt) [solo admin]
        // - GET    /api/v1/imports/{batch}                         → detalle de batch
        // - GET    /api/v1/imports/{batch}/rows                    → filas (?status=partial_match|no_match|...)
        // - PATCH  /api/v1/imports/{batch}/rows/{row}/resolve      → resolver fila [solo admin]
        // -----------------------------------------------------------------------
        Route::prefix('imports')->group(function () {
            Route::get('/',                                [ImportController::class, 'index']);
            Route::post('/',                               [ImportController::class, 'store']);
            Route::get('/template',                        [ImportController::class, 'template']);
            Route::get('/{batch}',                         [ImportController::class, 'show']);
            Route::get('/{batch}/rows',                    [ImportController::class, 'rows']);
            Route::patch('/{batch}/rows/{row}/resolve',    [ImportController::class, 'resolveRow']);
        });

        // -----------------------------------------------------------------------
        // Carga masiva de usuarios institucionales (rol institución o representante)
        // - GET    /api/v1/user-imports                                → listar batches
        // - POST   /api/v1/user-imports                                → subir archivo (CSV, TXT o Excel)
        // - GET    /api/v1/user-imports/template?format=               → descargar plantilla (xlsx|csv|txt)
        // - GET    /api/v1/user-imports/{batch}                        → detalle de batch
        // - GET    /api/v1/user-imports/{batch}/rows                   → filas (?status=needs_review|created|...)
        // - PATCH  /api/v1/user-imports/{batch}/rows/{row}/resolve     → resolver fila (confirm|skip)
        // Acceso: admin (cualquier institución) o responsable de institución (solo la propia)
        // -----------------------------------------------------------------------
        Route::prefix('user-imports')->group(function () {
            Route::get('/',                                [UserImportController::class, 'index']);
            Route::post('/',                               [UserImportController::class, 'store']);
            Route::get('/template',                        [UserImportController::class, 'template']);
            Route::get('/{batch}',                         [UserImportController::class, 'show']);
            Route::get('/{batch}/rows',                    [UserImportController::class, 'rows']);
            Route::patch('/{batch}/rows/{row}/resolve',    [UserImportController::class, 'resolveRow']);
        });

        // -----------------------------------------------------------------------
        // Registro educativo de un niño (uno por institución educativa)
        // - GET    /api/v1/children/{child}/education-record   → ver registro
        // - POST   /api/v1/children/{child}/education-record   → crear registro
        // - PATCH  /api/v1/children/{child}/education-record   → modificar registro
        // - DELETE /api/v1/children/{child}/education-record   → dar de baja (solo admin)
        // -----------------------------------------------------------------------
        Route::prefix('children/{child}')->group(function () {
            Route::get('education-record', [EducationRecordController::class, 'show']);
            Route::post('education-record', [EducationRecordController::class, 'store']);
            Route::patch('education-record', [EducationRecordController::class, 'update']);
            Route::delete('education-record', [EducationRecordController::class, 'destroy']);

            // Bitácora de observaciones del registro educativo (texto + PDF opcional)
            Route::get('education-record/observations', [EducationObservationController::class, 'index']);
            Route::post('education-record/observations', [EducationObservationController::class, 'store']);

            // -----------------------------------------------------------------------
            // Registro de salud de un niño (uno por institución de salud)
            // - GET    /api/v1/children/{child}/health-record   → ver registro
            // - POST   /api/v1/children/{child}/health-record   → crear registro
            // - PATCH  /api/v1/children/{child}/health-record   → modificar registro
            // - DELETE /api/v1/children/{child}/health-record   → dar de baja (solo admin)
            // -----------------------------------------------------------------------
            Route::get('health-record', [HealthRecordController::class, 'show']);
            Route::post('health-record', [HealthRecordController::class, 'store']);
            Route::patch('health-record', [HealthRecordController::class, 'update']);
            Route::delete('health-record', [HealthRecordController::class, 'destroy']);

            // Bitácora de observaciones del registro de salud (texto + PDF opcional)
            // Solo la institución de salud puede crear entradas (no representantes).
            Route::get('health-record/observations', [HealthObservationController::class, 'index']);
            Route::post('health-record/observations', [HealthObservationController::class, 'store']);

            // -----------------------------------------------------------------------
            // Registro de nacimiento de un niño (uno por niño, mayormente vía importación)
            // - GET    /api/v1/children/{child}/birth-record   → ver registro [admin/coordinador]
            // - POST   /api/v1/children/{child}/birth-record   → crear registro [solo admin]
            // - PATCH  /api/v1/children/{child}/birth-record   → corregir registro [solo admin]
            // - DELETE /api/v1/children/{child}/birth-record   → dar de baja [solo admin]
            // -----------------------------------------------------------------------
            Route::get('birth-record', [BirthRecordController::class, 'show']);
            Route::post('birth-record', [BirthRecordController::class, 'store']);
            Route::patch('birth-record', [BirthRecordController::class, 'update']);
            Route::delete('birth-record', [BirthRecordController::class, 'destroy']);

            // -----------------------------------------------------------------------
            // Registro de defunción de un niño (uno por niño, mayormente vía importación)
            // - GET    /api/v1/children/{child}/death-record   → ver registro [admin/coordinador]
            // - POST   /api/v1/children/{child}/death-record   → crear registro [solo admin]
            // - PATCH  /api/v1/children/{child}/death-record   → corregir registro [solo admin]
            // - DELETE /api/v1/children/{child}/death-record   → dar de baja [solo admin]
            // -----------------------------------------------------------------------
            Route::get('death-record', [DeathRecordController::class, 'show']);
            Route::post('death-record', [DeathRecordController::class, 'store']);
            Route::patch('death-record', [DeathRecordController::class, 'update']);
            Route::delete('death-record', [DeathRecordController::class, 'destroy']);
        });
    });
});
