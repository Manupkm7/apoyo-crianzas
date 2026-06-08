<?php

namespace App\Providers;

use App\Models\Institution;
use App\Models\User;
use App\Policies\InstitutionPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * AppServiceProvider — Configuración inicial de la aplicación.
 *
 * Aquí se registran de forma explícita las "Policies" (reglas de autorización)
 * de cada modelo. Una Policy es el conjunto de reglas que define quién puede
 * ver, crear, modificar o eliminar cada tipo de dato.
 *
 * Aunque Laravel puede descubrir las Policies automáticamente por convención de nombres,
 * las registramos aquí explícitamente para que sea claro y fácil de auditar.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra servicios de la aplicación (llamado antes del boot).
     * Por ahora no se necesita registrar nada adicional aquí.
     */
    public function register(): void
    {
        //
    }

    /**
     * Configura los servicios de la aplicación una vez que están cargados.
     *
     * Aquí mapeamos cada modelo a su Policy correspondiente.
     * Formato: Gate::policy(Modelo::class, Policy::class)
     */
    public function boot(): void
    {
        // Instituciones — gestión del catálogo de instituciones municipales
        Gate::policy(Institution::class, InstitutionPolicy::class);

        // Usuarios — gestión de cuentas de usuario del sistema
        Gate::policy(User::class, UserPolicy::class);
    }
}
