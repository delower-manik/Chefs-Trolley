<?php

/*
 | Cross-origin access for the browser apps. The API authenticates with a
 | bearer token (Authorization header) and never uses cookies, so
 | `supports_credentials` stays false. In production set CORS_ALLOWED_ORIGINS
 | to the exact admin/website origins instead of leaving it open.
 */
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
