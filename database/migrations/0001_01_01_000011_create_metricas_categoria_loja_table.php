<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('metricas_categoria_loja', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('loja_id');
        $table->uuid('categoria_id');
        $table->date('periodo_referencia');
        $table->decimal('giro_medio_dias', 8, 2)->nullable();
        $table->decimal('margem_realizada_media', 5, 2)->nullable();
        $table->decimal('margem_planejada_media', 5, 2)->nullable();
        $table->integer('qtd_vendas_periodo')->default(0);
        $table->boolean('volume_minimo_atingido')->default(false);
        $table->json('candidatos_liquidacao')->nullable();
        $table->timestamp('data_calculo')->useCurrent();
        $table->foreign('loja_id')->references('id')->on('lojas')->onDelete('cascade');
        $table->foreign('categoria_id')->references('id')->on('categorias');
        $table->unique(['loja_id', 'categoria_id', 'periodo_referencia'], 'uk_metricas_loja_cat_periodo');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metricas_categoria_loja');
    }
};
