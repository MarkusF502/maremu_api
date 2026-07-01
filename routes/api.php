<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// Rotas públicas (Não exigem estar logado)
Route::post('/auth/login', [AuthController::class, 'login']);

// Rotas protegidas (Exigem o cookie do Sanctum válido)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});