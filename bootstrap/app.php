<?php

use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetPostgresUserContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Append security headers to every response
        $middleware->append(SecurityHeaders::class);

        // Set PostgreSQL RLS context for authenticated API requests
        $middleware->appendToGroup('api', SetPostgresUserContext::class);

        // Rate limiting for auth endpoints (60 attempts/minute per IP)
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
