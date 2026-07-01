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
    Schema::create('logs_sugestao_ia', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('produto_id');
        $table->json('payload_enviado');
        $table->json('cenarios_retornados')->nullable();
        $table->enum('cenario_escolhido', [
            'cenario_1', 'cenario_2', 'cenario_3', 'manual'
        ])->nullable();
        $table->decimal('preco_final_escolhido', 10, 2)->nullable();
        $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_sugestao_ia');
    }
};
