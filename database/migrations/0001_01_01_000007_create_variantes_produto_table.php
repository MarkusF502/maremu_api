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
    Schema::create('variantes_produto', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('produto_id');
        $table->string('tamanho');
        $table->integer('quantidade_estoque')->default(0);
        $table->integer('estoque_minimo_alerta')->default(3);
        $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variantes_produto');
    }
};
