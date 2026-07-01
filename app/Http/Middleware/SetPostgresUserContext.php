<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets PostgreSQL session variables used by Row-Level Security policies.
 *
 * Uses SET LOCAL inside a transaction so variables are scoped to the request.
 * All values are parameterized to prevent SQL injection.
 *
 * Variables set:
 *   app.current_user_id        — UUID del usuario autenticado
 *   app.current_institution_id — UUID de la institución del usuario (vacío si no tiene)
 *   app.bypass_rls             — 'on' para admin/coordinador, 'off' para los demás
 *
 * IMPORTANTE: En producción el usuario de BD no debe tener BYPASSRLS ni ser superuser.
 */
class SetPostgresUserContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        DB::beginTransaction();

        try {
            $bypass        = $user->canBypassRls() ? 'on' : 'off';
            $userId        = (string) $user->id;
            $institutionId = (string) ($user->institution_id ?? '');

            DB::statement('SET LOCAL app.current_user_id = ?', [$userId]);
            DB::statement('SET LOCAL app.current_institution_id = ?', [$institutionId]);
            DB::statement('SET LOCAL app.bypass_rls = ?', [$bypass]);

            $response = $next($request);

            DB::commit();

            return $response;
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
