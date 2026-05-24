<?php
// CORS allow-list. Read from CORS_ORIGINS env (comma-separated) so production
// stays locked to known hosts without code changes. Localhost ports cover Vite
// dev (5173) and `php -S` (8080). Note: Response::corsHeaders() already honors
// FRONTEND_URL + APP_URL — this config file is informational/future-use.
$envOrigins = trim((string) (getenv('CORS_ORIGINS') ?: ''));
$origins = $envOrigins !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $envOrigins))))
    : [
        'https://smappen.mygreendock.com',
        'http://localhost:5173',
        'http://localhost:8080',
    ];

return [
    'allowed_origins' => $origins,
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
    'allowed_headers' => ['Authorization', 'Content-Type', 'X-Requested-With', 'X-CSRF-Token', 'Stripe-Signature'],
    'expose_headers' => [],
    'max_age' => 86400,
    'allow_credentials' => true,
];
