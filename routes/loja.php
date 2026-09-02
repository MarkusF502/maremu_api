<?php

use App\Http\Controllers\LojaController;
use App\Http\Controllers\OnboardingIaController;
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

    // ── Onboarding via IA (SPEC-onboarding-ia) ────────────────────────────
    // Tela 2: texto dissertativo → estimativas da IA (ou fallback pra Tela 2B)
    // throttle:5,1 protege a quota do Gemini (SPEC §6.1 / S7)
    Route::middleware('throttle:5,1')->post('/analisar-texto', [OnboardingIaController::class, 'analisarTexto']);

    // Wizard de pendências (Spec-Extracao-Assertiva-Onboarding-Maremu §8.2):
    // resolve custo_fixo_mensal/faturamento_medio_mensal por termos — nunca chama IA.
    Route::post('/responder-pendencias', [OnboardingIaController::class, 'responderPendencias']);

    // Tela 3: confirma/edita as estimativas e cria a loja
    Route::post('/confirmar-ia', [OnboardingIaController::class, 'confirmar']);

});

// ── Configurações (usuário logado com loja) ───────────────────────────────
Route::middleware(['auth:sanctum', EnsureLojaExists::class])->prefix('loja')->group(function () {

    // Tela de configurações: ler dados atuais da loja
    Route::get('/configuracoes', [LojaController::class, 'configuracoes']);

    // Tela de configurações: salvar edições
    Route::put('/configuracoes', [LojaController::class, 'atualizarConfiguracoes']);

});