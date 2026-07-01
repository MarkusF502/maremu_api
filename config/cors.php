<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:3000',
        env('FRONTEND_URL'),
    ],
    'allowed_headers' => ['*'],
    'supports_credentials' => true,
];