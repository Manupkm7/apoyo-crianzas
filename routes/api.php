<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\EducationRecordController;
use App\Http\Controllers\Api\HealthRecordController;
use App\Http\Controllers\Api\InstitutionController;
use App\Http\Controllers\Api\UserController;
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
    });

    // -------------------------------------------------------------------------
    // Endpoints autenticados — requieren token de Sanctum válido
    // -------------------------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {

        // Sesión del usuario actual
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me'])->name('me');

        // -----------------------------------------------------------------------
        // ABM de Instituciones
        // - GET    /api/v1/institutions         → listar instituciones
        // - POST   /api/v1/institutions         → crear institución [solo admin]
        // - GET    /api/v1/institutions/{id}    → ver institución
        // - PATCH  /api/v1/institutions/{id}    → modificar institución [solo admin]
        // - DELETE /api/v1/institutions/{id}    → desactivar institución [solo admin]
        // -----------------------------------------------------------------------
        Route::apiResource('institutions', InstitutionController::class);

        // -----------------------------------------------------------------------
        // ABM de Usuarios
        // - GET    /api/v1/users         → listar usuarios
        // - POST   /api/v1/users         → crear usuario
        // - GET    /api/v1/users/{id}    → ver perfil de usuario
        // - PATCH  /api/v1/users/{id}    → modificar usuario
        // - DELETE /api/v1/users/{id}    → desactivar usuario
        // -----------------------------------------------------------------------
        Route::apiResource('users', UserController::class);

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
        });
    });
});
