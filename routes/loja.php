<?php

use App\Http\Controllers\LojaController;
use App\Http\Middleware\EnsureLojaExists;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas de Loja — Onboarding e Configurações
|--------------------------------------------------------------------------
|
| Grupo 1: Onboarding — autenticado, mas SEM o middleware EnsureLojaExists.
|   O usuário ainda não tem loja, então não pode ser barrado por ele.
|
| Grupo 2: Configurações — autenticado E com loja já existente.
|
*/

// ── Onboarding (usuário logado sem loja) ─────────────────────────────────
Route::middleware('auth:sanctum')->prefix('loja/onboarding')->group(function () {

    // Etapa 1: recebe as 4 respostas, devolve os dados pré-preenchidos
    // O frontend usa esse retorno para montar a tela de revisão
    Route::post('/inferir', [LojaController::class, 'inferir']);

    // Etapa 2: recebe os dados revisados/confirmados e salva no banco
    // Após isso o usuário tem acesso ao restante do sistema
    Route::post('/salvar', [LojaController::class, 'salvar']);

});

// ── Configurações (usuário logado com loja) ───────────────────────────────
Route::middleware(['auth:sanctum', EnsureLojaExists::class])->prefix('loja')->group(function () {

    // Tela de configurações: ler dados atuais da loja
    Route::get('/configuracoes', [LojaController::class, 'configuracoes']);

    // Tela de configurações: salvar edições
    Route::put('/configuracoes', [LojaController::class, 'atualizarConfiguracoes']);

});