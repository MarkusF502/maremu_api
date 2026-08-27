<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter([
        'http://localhost:3000',
        'https://maremu-l2cr.vercel.app',
        'https://maremu.com.br',
        'https://www.maremu.com.br',
        env('FRONTEND_URL'),
    ]),

    // Preview deploys da Vercel usam URLs geradas por branch/commit
    // (ex.: maremu-git-minha-branch-usuario.vercel.app, maremu-abc123.vercel.app),
    // então não dá pra listar uma a uma em allowed_origins. Ajuste
    // VERCEL_PROJECT se o slug do projeto na Vercel não for "maremu".
    'allowed_origins_patterns' => [
        '#^https://'.preg_quote(env('VERCEL_PROJECT', 'maremu'), '#').'(-[a-z0-9-]+)*\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    // Migração para Sanctum API Tokens (Bearer): a autenticação não depende
    // mais de cookie de sessão cross-site, então 'supports_credentials' não é
    // mais estritamente necessário para o fluxo de auth. Mantido em 'true'
    // por não ser prejudicial e por não haver requisito de remoção imediata.
    'supports_credentials' => true,
];