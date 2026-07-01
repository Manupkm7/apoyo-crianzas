<?php

/*
|--------------------------------------------------------------------------
| CORS — Sistema de Apoyo a la Crianza
|--------------------------------------------------------------------------
| El frontend es un sitio WordPress que consume esta API REST.
| Configurar FRONTEND_URL en .env con el dominio real de WordPress en producción.
| En desarrollo se permite localhost:8080 y el puerto 80 por defecto de WordPress.
|
| Credential support (supports_credentials = true) es necesario si WordPress
| envía las requests con cookies de sesión o headers de autorización.
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter([
        env('FRONTEND_URL'),
        env('APP_ENV') === 'local' ? 'http://localhost' : null,
        env('APP_ENV') === 'local' ? 'http://localhost:8080' : null,
        env('APP_ENV') === 'local' ? 'http://127.0.0.1' : null,
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
