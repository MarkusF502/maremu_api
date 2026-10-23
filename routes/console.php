<?php

use App\Jobs\AgregarMetricasCategoria;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Disparado 1x/dia pelo Container Apps Job (cron no deploy-azure.sh), não pelo
// scheduler do Laravel: não há processo rodando `schedule:run` no Azure.
Artisan::command('metricas:agregar', function () {
    AgregarMetricasCategoria::dispatchSync();
    $this->info('Métricas por categoria agregadas.');
})->purpose('Atualiza a janela de métricas de cada loja e categoria (um registro por janela)');
