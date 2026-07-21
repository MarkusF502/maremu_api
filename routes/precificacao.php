<?php

use App\Http\Controllers\PrecificacaoController;
use App\Http\Middleware\EnsureLojaExists;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', EnsureLojaExists::class])->prefix('precificacao')->group(function () {

    // Fase 2: monta e retorna o payload estruturado das camadas 1, 2 e 4
    // Chamado pelo frontend logo após o produto ser cadastrado
    Route::post('/sugerir/{produto}', [PrecificacaoController::class, 'sugerir']);

    // Salva a escolha do lojista (cenário da IA ou preço manual)
    Route::post('/confirmar', [PrecificacaoController::class, 'confirmar']);

});