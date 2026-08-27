<?php

namespace App\Providers;

use App\Services\AnthropicService;
use App\Services\GeminiService;
use App\Services\PrecificacaoIaInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Provedor de IA para sugestão de margens de precificação, selecionável
        // via IA_PROVIDER (config('services.ia_provider')) sem que
        // PrecificacaoController ou TestarVarianciaPrecificacao precisem saber
        // qual implementação está ativa.
        $this->app->bind(PrecificacaoIaInterface::class, function () {
            return match (config('services.ia_provider')) {
                'anthropic' => $this->app->make(AnthropicService::class),
                default     => $this->app->make(GeminiService::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
