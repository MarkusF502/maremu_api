<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uma linha por chamada de POST /precificacao/sugerir que respondeu 200:
     * o que a recomendação de cenário mostrou naquela rodada. A escolha do
     * lojista fica em logs_sugestao_ia; a taxa de aceitação sai do join.
     *
     * Todas as chaves são cascade: apagar o produto, a loja ou a rodada de
     * sugestão apaga a recomendação junto (o DemoSeeder conta com isso).
     */
    public function up(): void
    {
        Schema::create('logs_recomendacao_cenario', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('produto_id');
            $table->uuid('loja_id');
            $table->uuid('log_sugestao_ia_id');
            // Nulo quando a recomendação estava indisponível.
            $table->enum('cenario_recomendado', ['liquidacao', 'ideal', 'alta_demanda'])->nullable();
            $table->string('origem_recomendacao', 30);
            $table->string('motivo_indisponivel', 30)->nullable();
            $table->json('sinais');
            $table->string('versao_regras', 20);
            $table->timestamps();

            $table->foreign('produto_id')->references('id')->on('produtos')->cascadeOnDelete();
            $table->foreign('loja_id')->references('id')->on('lojas')->cascadeOnDelete();
            $table->foreign('log_sugestao_ia_id')->references('id')->on('logs_sugestao_ia')->cascadeOnDelete();
            $table->index(['loja_id', 'versao_regras'], 'ix_logs_recomendacao_loja_versao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs_recomendacao_cenario');
    }
};
