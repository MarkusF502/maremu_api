<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec-Extracao-Assertiva-Onboarding-Maremu §7.
 *
 * Extensão de `logs_onboarding_ia` para suportar o fluxo de pendências de
 * custo_fixo_mensal/faturamento_medio_mensal: enquanto o lojista não resolve
 * as pendências geradas por analisar-texto, o registro fica com
 * status='pendente' e o draft de termos (aceitos + pendentes) em
 * termos_detalhados. responder-pendencias localiza o registro por
 * sessao_id (= id desta tabela), mescla as respostas e marca 'concluido'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logs_onboarding_ia', function (Blueprint $table) {
            $table->json('termos_detalhados')->nullable()->after('estimativas_finais');
            $table->enum('status', ['pendente', 'concluido'])->default('concluido')->after('termos_detalhados');
        });
    }

    public function down(): void
    {
        Schema::table('logs_onboarding_ia', function (Blueprint $table) {
            $table->dropColumn(['termos_detalhados', 'status']);
        });
    }
};
