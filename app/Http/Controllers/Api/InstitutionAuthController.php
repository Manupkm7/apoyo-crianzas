<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Institution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * InstitutionAuthController — Login institucional (provincia → departamento →
 * localidad → sector → institución → contraseña).
 *
 * No reemplaza el login de AuthController: es un segundo camino de acceso.
 * Autentica a la Institution como su propio actor (ver Institution::class,
 * que implementa SystemActor igual que User) y emite un token de Sanctum
 * propio. Misma política de seguridad que AuthController::login (bloqueo
 * tras intentos fallidos, verificación de hash a tiempo constante).
 */
class InstitutionAuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES     = 15;

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'institution_id' => ['required', 'uuid', 'exists:institutions,id'],
            'password'       => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $institution = Institution::find($request->institution_id);

        $passwordValid = $institution
            && $institution->password
            && Hash::check($request->password, $institution->password);

        if (! $institution || ! $passwordValid) {
            if ($institution) {
                $institution->registerFailedLoginAttempt(self::MAX_FAILED_ATTEMPTS, self::LOCKOUT_MINUTES);

                activity('auth')
                    ->causedBy($institution)
                    ->withProperties(['ip' => $request->ip()])
                    ->log('Intento de inicio de sesión institucional fallido');
            }

            throw ValidationException::withMessages([
                'institution_id' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        if (! $institution->is_active) {
            throw ValidationException::withMessages([
                'institution_id' => ['Esta institución se encuentra desactivada.'],
            ]);
        }

        if ($institution->isLocked()) {
            throw ValidationException::withMessages([
                'institution_id' => ['Institución bloqueada temporalmente por exceso de intentos fallidos.'],
            ]);
        }

        $institution->registerSuccessfulLogin($request->ip());

        activity('auth')
            ->causedBy($institution)
            ->withProperties(['ip' => $request->ip()])
            ->log('Inicio de sesión institucional');

        $token = $institution->createToken('institution-api-token', ['*'], now()->addHours(8))->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'institution' => [
                'id'                    => $institution->id,
                'name'                  => $institution->name,
                'type'                  => $institution->type,
                'roles'                 => $institution->getRoleNames(),
                'permissions'           => $institution->getAllPermissions()->pluck('name'),
                'password_must_change'  => $institution->password_must_change,
            ],
        ]);
    }

    /**
     * La institución autenticada cambia su propia contraseña.
     *
     * Pensado para el caso "el admin me reseteó la clave y me quedó una cadena
     * aleatoria imposible de recordar": la institución entra con esa clave y la
     * reemplaza por una propia. Requiere la contraseña actual. Al cambiarla se
     * baja el flag password_must_change y se cierran las demás sesiones.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $institution = $request->user();

        if (! $institution instanceof Institution) {
            abort(403, 'Solo una institución autenticada puede cambiar su propia contraseña.');
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            // Misma política que el resto del sistema (StoreUserRequest): datos de menores.
            'password'         => ['required', 'string', 'confirmed', 'different:current_password',
                Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if (! $institution->password || ! Hash::check($validated['current_password'], $institution->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['La contraseña actual es incorrecta.'],
            ]);
        }

        $institution->update([
            'password'             => $validated['password'],
            'password_must_change' => false,
        ]);

        // Cerramos el resto de las sesiones de la institución; la actual sigue viva.
        if ($currentTokenId = $institution->currentAccessToken()?->getKey()) {
            $institution->tokens()->whereKeyNot($currentTokenId)->delete();
        }

        activity('auth')
            ->causedBy($institution)
            ->withProperties(['ip' => $request->ip()])
            ->log('Cambio de contraseña institucional');

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }
}
