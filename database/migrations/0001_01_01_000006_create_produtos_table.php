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
    Schema::create('produtos', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('loja_id');
        $table->uuid('categoria_id');
        $table->string('nome');
        $table->string('sku')->nullable();
        $table->decimal('custo_aquisicao', 10, 2);
        $table->decimal('frete_entrada_unitario', 10, 2)->default(0);
        $table->decimal('preco_piso', 10, 2)->nullable();
        $table->decimal('preco_venda_atual', 10, 2)->nullable();
        $table->enum('preco_origem', [
            'ia_cenario_1', 'ia_cenario_2', 'ia_cenario_3', 'manual'
        ])->nullable();
        $table->enum('status', ['ativo', 'liquidacao', 'inativo'])->default('ativo');
        $table->timestamp('data_cadastro')->useCurrent();
        $table->foreign('loja_id')->references('id')->on('lojas')->onDelete('cascade');
        $table->foreign('categoria_id')->references('id')->on('categorias');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produtos');
    }
};
