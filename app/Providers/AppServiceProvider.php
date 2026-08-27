<?php

namespace App\Providers;

use App\Models\BirthRecord;
use App\Models\Child;
use App\Models\DeathRecord;
use App\Models\EducationRecord;
use App\Models\HealthRecord;
use App\Models\Institution;
use App\Models\User;
use App\Policies\BirthRecordPolicy;
use App\Policies\ChildPolicy;
use App\Policies\DeathRecordPolicy;
use App\Policies\EducationRecordPolicy;
use App\Policies\HealthRecordPolicy;
use App\Policies\InstitutionPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        // Rate limiter 'api': 60 requests/minuto por usuario autenticado o por IP.
        // Requerido por throttleApi() en bootstrap/app.php.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // El guard 'sanctum' está atado al provider 'users' (lo necesita
        // Spatie\Permission para resolver el modelo del guard). Por eso Sanctum
        // rechaza vía Guard::hasValidProvider() todo token cuyo tokenable no sea
        // App\Models\User — incluidos los de Institution (login institucional,
        // ver InstitutionAuthController). Revalidamos esos tokens a mano:
        // comprobamos el vencimiento igual que hace Sanctum con expires_at.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $accessToken, bool $isValid) {
            if ($isValid) {
                return true;
            }

            if (! ($accessToken->tokenable instanceof Institution)) {
                return false;
            }

            return $accessToken->expires_at === null
                || $accessToken->expires_at->isFuture();
        });

        // Instituciones — gestión del catálogo de instituciones municipales
        Gate::policy(Institution::class, InstitutionPolicy::class);

        // Usuarios — gestión de cuentas de usuario del sistema
        Gate::policy(User::class, UserPolicy::class);

        // Niños — registro base compartido entre módulos
        Gate::policy(Child::class, ChildPolicy::class);

        // Registros educativos — solo para instituciones de tipo 'educacion'
        Gate::policy(EducationRecord::class, EducationRecordPolicy::class);

        // Registros de salud — solo para instituciones de tipo 'salud'
        Gate::policy(HealthRecord::class, HealthRecordPolicy::class);

        // Registros de nacimiento y defunción — solo admin/coordinador los ven,
        // solo admin los crea/edita/elimina manualmente (ver policies para el detalle)
        Gate::policy(BirthRecord::class, BirthRecordPolicy::class);
        Gate::policy(DeathRecord::class, DeathRecordPolicy::class);
    }
}
