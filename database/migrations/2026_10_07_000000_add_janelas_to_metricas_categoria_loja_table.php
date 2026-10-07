<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Transforma a tabela de "um registro por loja e categoria, sobrescrito"
     * em "um registro por janela": cada linha passa a ser um snapshot.
     * periodo_referencia continua existindo e equivale ao fim da janela.
     */
    public function up(): void
    {
        Schema::table('metricas_categoria_loja', function (Blueprint $table) {
            // Janela
            $table->date('periodo_inicio')->nullable()->after('periodo_referencia');
            $table->unsignedSmallInteger('janela_dias')->nullable()->after('periodo_inicio');
            $table->boolean('fechada')->default(false)->after('janela_dias');

            // Vendas dentro da janela (base da tendência temporal, V2)
            $table->integer('unidades_vendidas_janela')->default(0)->after('qtd_vendas_periodo');
            $table->decimal('vendas_por_dia', 10, 4)->nullable()->after('unidades_vendidas_janela');

            // Fotografia do catálogo com estoque no momento do cálculo (camada 3)
            $table->decimal('preco_mediano', 10, 2)->nullable()->after('vendas_por_dia');
            $table->decimal('margem_mediana', 7, 4)->nullable()->after('preco_mediano');
            $table->integer('n_produtos')->default(0)->after('margem_mediana');
            $table->integer('estoque_total')->default(0)->after('n_produtos');

            $table->index(['categoria_id', 'periodo_referencia'], 'ix_metricas_cat_periodo');
        });

        // Margens são frações (0,3437): com 2 casas a razão de margem perdia precisão.
        Schema::table('metricas_categoria_loja', function (Blueprint $table) {
            $table->decimal('margem_realizada_media', 7, 4)->nullable()->change();
            $table->decimal('margem_planejada_media', 7, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('metricas_categoria_loja', function (Blueprint $table) {
            $table->decimal('margem_realizada_media', 5, 2)->nullable()->change();
            $table->decimal('margem_planejada_media', 5, 2)->nullable()->change();
        });

        Schema::table('metricas_categoria_loja', function (Blueprint $table) {
            $table->dropIndex('ix_metricas_cat_periodo');
            $table->dropColumn([
                'periodo_inicio', 'janela_dias', 'fechada',
                'unidades_vendidas_janela', 'vendas_por_dia',
                'preco_mediano', 'margem_mediana', 'n_produtos', 'estoque_total',
            ]);
        });
    }
};
