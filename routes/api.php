<?php

use App\Http\Controllers\Api\AuthController;
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
    });
});
