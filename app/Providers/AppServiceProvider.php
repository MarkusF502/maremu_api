<?php

namespace App\Providers;

use App\Services\AnthropicService;
use App\Services\GeminiService;
use App\Services\OnboardingIaAnthropicService;
use App\Services\OnboardingIaGeminiService;
use App\Services\OnboardingIaInterface;
use App\Services\OpenAiService;
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
                'openai'    => $this->app->make(OpenAiService::class),
                default     => $this->app->make(GeminiService::class),
            };
        });

        // Mesmo switch de provedor (IA_PROVIDER) aplicado ao onboarding via IA
        // (Tela 2 do onboarding — ver OnboardingIaController). Sem implementação
        // própria de OpenAI aqui (o provedor 'openai' existe só para o
        // experimento de variância da precificação) — cai no Gemini por padrão
        // nesse caso.
        $this->app->bind(OnboardingIaInterface::class, function () {
            return match (config('services.ia_provider')) {
                'anthropic' => $this->app->make(OnboardingIaAnthropicService::class),
                default     => $this->app->make(OnboardingIaGeminiService::class),
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
