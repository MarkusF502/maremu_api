<?php

use Illuminate\Support\Facades\Route;

// A view 'welcome' padrão do Laravel usa @vite, que quebra em produção porque
// os assets de resources/ nunca são buildados aqui (o front real é o app
// Next.js separado). Trocado por uma resposta simples só pra essa rota não
// dar 500 se alguém acessar a raiz da API.
Route::get('/', function () {
    return response()->json(['status' => 'ok', 'service' => 'maremu-api']);
});
