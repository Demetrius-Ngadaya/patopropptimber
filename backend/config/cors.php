<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Accept-Language', 'Authorization', 'X-Branch-Id', 'X-Locale', 'X-Requested-With', 'X-Business'],

    'exposed_headers' => ['Content-Disposition', 'X-Business-State'],

    'max_age' => 3600,

    'supports_credentials' => false,
];
