<?php

namespace App\Models\Concerns;

/*
 * Bloqueo de cuenta tras intentos fallidos, compartido entre cualquier actor
 * autenticable por contraseña (User, Institution). Ambas tablas tienen las
 * mismas columnas: failed_login_attempts, locked_until, last_login_at,
 * last_login_ip. La política (5 intentos, 15 minutos) vive en AuthController
 * y en InstitutionAuthController, que llaman a estos métodos.
 */
trait HasLoginLockout
{
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function registerFailedLoginAttempt(int $maxAttempts, int $lockoutMinutes): void
    {
        $attempts = $this->failed_login_attempts + 1;

        $this->update([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= $maxAttempts
                ? now()->addMinutes($lockoutMinutes)
                : null,
        ]);
    }

    public function registerSuccessfulLogin(string $ip): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }
}
