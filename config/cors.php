<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:3000',
        'https://maremu-l2cr.vercel.app',
        'https://maremu.com.br',
        'https://www.maremu.com.br',
        env('FRONTEND_URL'),
    ],
    'allowed_headers' => ['*'],
    'supports_credentials' => true,
];