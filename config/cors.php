<?php

/*
|--------------------------------------------------------------------------
| CORS — Sistema de Apoyo a la Crianza
|--------------------------------------------------------------------------
| Cuando la aplicación corre con nginx + Cloudflare Tunnel, el frontend y
| el backend comparten el mismo dominio, por lo que CORS no es un problema
| en producción. Las reglas aquí cubren el acceso directo al backend y
| futuros consumidores (WordPress, apps móviles, etc.).
|
| Variables de entorno relevantes:
|   FRONTEND_URL   → URL pública del frontend (Cloudflare Tunnel o producción)
|   APP_ENV=local  → activa los orígenes de desarrollo (localhost, 5173, etc.)
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL'),
        env('APP_ENV') === 'local' ? 'http://localhost' : null,
        env('APP_ENV') === 'local' ? 'http://localhost:8080' : null,
        env('APP_ENV') === 'local' ? 'http://localhost:5173' : null, // Vite dev server
        env('APP_ENV') === 'local' ? 'http://127.0.0.1' : null,
        env('APP_ENV') === 'local' ? 'http://127.0.0.1:5173' : null, // Vite dev server (alternativo)
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
    ],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,

];
