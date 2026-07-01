<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $user = User::where('email', $request->email)->first();

        // Always run Hash::check to prevent timing attacks (don't short-circuit on missing user)
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

        // Reset failed attempts on successful login
        $user->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        Log::info('User login', ['user_id' => $user->id, 'ip' => $request->ip()]);

        $token = $user->createToken('api-token', ['*'], now()->addHours(8))->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'institution' => $user->institution?->only(['id', 'name', 'type']),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('institution');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'institution' => $user->institution?->only(['id', 'name', 'type']),
        ]);
    }

    private function handleFailedAttempt(User $user, Request $request): void
    {
        $attempts = $user->failed_login_attempts + 1;
        $lockedUntil = $attempts >= self::MAX_FAILED_ATTEMPTS
            ? now()->addMinutes(self::LOCKOUT_MINUTES)
            : null;

        $user->update([
            'failed_login_attempts' => $attempts,
            'locked_until' => $lockedUntil,
        ]);

        Log::warning('Failed login attempt', [
            'email' => $user->email,
            'ip' => $request->ip(),
            'attempts' => $attempts,
        ]);
    }
}
