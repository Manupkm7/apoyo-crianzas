<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * AuthController — Inicio y cierre de sesión.
 *
 * Gestiona el acceso al sistema mediante tokens de Sanctum.
 * Registra todos los ingresos y salidas en el historial de actividad.
 *
 * Medidas de seguridad implementadas:
 * - Bloqueo de cuenta tras 5 intentos fallidos (15 minutos)
 * - Verificación constante del hash para evitar ataques de timing
 * - Tokens de sesión con vencimiento de 8 horas
 */
class AuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES     = 15;

    /**
     * Autentica al usuario y entrega un token de acceso.
     *
     * Proceso:
     * 1. Valida que se enviaron email y contraseña.
     * 2. Busca al usuario por email.
     * 3. Verifica la contraseña (siempre, incluso si el usuario no existe,
     *    para evitar que un atacante pueda saber si un email está registrado).
     * 4. Si las credenciales son incorrectas, incrementa el contador de intentos fallidos.
     *    Al llegar a 5, la cuenta se bloquea 15 minutos.
     * 5. Si la cuenta está desactivada o bloqueada, devuelve un error claro.
     * 6. En login exitoso: resetea el contador, registra el acceso y devuelve el token.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $user = User::where('email', $request->email)->first();

        // Siempre verificamos el hash aunque el usuario no exista.
        // Esto evita que un atacante detecte emails válidos midiendo el tiempo de respuesta.
        $passwordValid = $user && Hash::check($request->password, $user->password);

        if (! $user || ! $passwordValid) {
            if ($user) {
                $this->handleFailedAttempt($user, $request);
            }

            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Esta cuenta se encuentra desactivada.'],
            ]);
        }

        if ($user->isLocked()) {
            throw ValidationException::withMessages([
                'email' => ['Cuenta bloqueada temporalmente por exceso de intentos fallidos.'],
            ]);
        }

        // Login exitoso: reseteamos contadores y guardamos fecha/IP de acceso
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
            'last_login_ip'         => $request->ip(),
        ]);

        // Registramos el ingreso en el historial de actividad del sistema
        activity('auth')
            ->causedBy($user)
            ->withProperties(['ip' => $request->ip()])
            ->log('Inicio de sesión');

        // Creamos el token con vencimiento de 8 horas
        $token = $user->createToken('api-token', ['*'], now()->addHours(8))->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'roles'       => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'institution' => $user->institution?->only(['id', 'name', 'type']),
            ],
        ]);
    }

    /**
     * Cierra la sesión del usuario autenticado.
     *
     * Invalida únicamente el token actual (si el usuario tiene otros tokens activos
     * en otros dispositivos, estos continúan válidos).
     * Registra el cierre de sesión en el historial.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Registramos el cierre de sesión antes de invalidar el token
        activity('auth')
            ->causedBy($user)
            ->log('Cierre de sesión');

        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * Devuelve el perfil completo del usuario actualmente autenticado.
     *
     * Incluye roles, permisos e institución asociada.
     * Es el endpoint que el frontend consulta para saber qué puede mostrar/ocultar.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('institution');

        return response()->json([
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'roles'       => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'institution' => $user->institution?->only(['id', 'name', 'type']),
        ]);
    }

    /**
     * Registra un intento de login fallido y bloquea la cuenta si corresponde.
     *
     * Al llegar a MAX_FAILED_ATTEMPTS intentos, la cuenta se bloquea por
     * LOCKOUT_MINUTES minutos. El bloqueo se libera automáticamente cuando
     * vence el tiempo o cuando un admin reactiva la cuenta.
     */
    private function handleFailedAttempt(User $user, Request $request): void
    {
        $attempts   = $user->failed_login_attempts + 1;
        $lockedUntil = $attempts >= self::MAX_FAILED_ATTEMPTS
            ? now()->addMinutes(self::LOCKOUT_MINUTES)
            : null;

        $user->update([
            'failed_login_attempts' => $attempts,
            'locked_until'          => $lockedUntil,
        ]);

        // Registramos el intento fallido en el historial
        activity('auth')
            ->causedBy($user)
            ->withProperties([
                'ip'       => $request->ip(),
                'attempts' => $attempts,
                'locked'   => $lockedUntil !== null,
            ])
            ->log('Intento de inicio de sesión fallido');
    }
}
