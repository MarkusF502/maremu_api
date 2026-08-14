<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NOTA DE ADAPTAÇÃO À SPEC (§7):
     *
     * A spec original prevê `loja_id` como FK obrigatória. No fluxo real do
     * onboarding (ver LojaController::salvar), a loja só é criada no
     * "Confirmar" — igual ao fluxo determinístico existente. No momento de
     * `analisarTexto` a loja ainda não existe, então o log é amarrado ao
     * `user_id` (sempre disponível via auth:sanctum) e `loja_id` fica
     * nullable, preenchido só em `confirmar()` depois que a loja é criada.
     */
    public function up(): void
    {
        Schema::create('logs_onboarding_ia', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('loja_id')->nullable()->constrained('lojas')->cascadeOnDelete();

            $table->text('texto_original');
            $table->json('dados_factuais');           // nome, regime_tributario, canais_marcados
            $table->json('estimativas_ia')->nullable(); // snapshot do retorno do OnboardingIaService (null se fallback pré-API)
            $table->json('estimativas_finais')->nullable(); // valores confirmados pelo lojista, preenchido só em confirmar()

            $table->boolean('usou_fallback')->default(false);
            $table->enum('motivo_fallback', [
                'texto_insuficiente',
                'erro_api',
                'confianca_insuficiente',
            ])->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_onboarding_ia');
    }
};
