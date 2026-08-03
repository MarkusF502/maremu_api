<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ProdutoController;
use App\Http\Controllers\RelatorioController;
use App\Http\Controllers\SaidaController;
use App\Http\Middleware\EnsureLojaExists;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PrecificacaoController;
use Illuminate\Support\Facades\Route;

// ── Rotas públicas (sem autenticação) ────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// ── Rotas autenticadas ────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

// ── Operações da loja ─────────────────────────────────────────────────────
Route::middleware(['auth:sanctum', EnsureLojaExists::class])->group(function () {
    Route::get('/categorias', [CategoriaController::class, 'index']);
    Route::post('/categorias', [CategoriaController::class, 'store']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/produtos', [ProdutoController::class, 'index']);
    Route::post('/produtos', [ProdutoController::class, 'store']);
    Route::get('/produtos/{produto}', [ProdutoController::class, 'show']);
    Route::put('/produtos/{produto}', [ProdutoController::class, 'update']);
    Route::delete('/produtos/{produto}', [ProdutoController::class, 'destroy']);
    Route::post('/precificacao/sugerir/{produto}', [PrecificacaoController::class, 'sugerir']);
    Route::post('/precificacao/confirmar', [PrecificacaoController::class, 'confirmar']);
    Route::get('/relatorio', [RelatorioController::class, 'index']);

    Route::get('/saidas/catalogo', [SaidaController::class, 'catalogo']);
    Route::get('/saidas', [SaidaController::class, 'index']);
    Route::post('/saidas', [SaidaController::class, 'store']);
});
