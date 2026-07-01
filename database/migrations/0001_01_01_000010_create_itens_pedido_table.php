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
    Schema::create('itens_pedido', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('pedido_id');
        $table->uuid('produto_id');
        $table->uuid('variante_id')->nullable();
        $table->integer('quantidade');
        $table->decimal('preco_unitario_vendido', 10, 2);
        $table->decimal('custo_unitario_no_momento', 10, 2);
        $table->decimal('desconto_aplicado', 10, 2)->default(0);
        $table->foreign('pedido_id')->references('id')->on('pedidos')->onDelete('cascade');
        $table->foreign('produto_id')->references('id')->on('produtos');
        $table->foreign('variante_id')->references('id')->on('variantes_produto')->nullOnDelete();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itens_pedido');
    }
};
