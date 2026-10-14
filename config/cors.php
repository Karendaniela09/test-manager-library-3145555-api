<?php

return [
    'paths' => ['api/*', 'health'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([env('CORS_ORIGIN', 'http://localhost:5173')]),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
