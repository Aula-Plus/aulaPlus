<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Security rule #8: CORS is restricted to the real application domains and is
| NEVER "*" in production. Allowed origins come from the FRONTEND_URL env var
| (comma-separated for multiple environments), so each environment configures
| its own value. `supports_credentials` is true because Sanctum SPA auth relies
| on cookies being sent cross-origin.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')))
    ),

    // In local dev, Vite binds the first free port (5173, then 5174, 5175, …),
    // so accept any localhost / 127.0.0.1 port. Gated to the local environment:
    // production only allows the exact origins listed in FRONTEND_URL.
    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^http://localhost:\d+$#', '#^http://127\.0\.0\.1:\d+$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
