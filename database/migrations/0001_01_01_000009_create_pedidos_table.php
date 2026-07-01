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
    Schema::create('pedidos', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('loja_id');
        $table->enum('canal_venda', [
            'loja_fisica', 'instagram_whatsapp', 'marketplace', 'outro'
        ]);
        $table->decimal('valor_total', 10, 2);
        $table->string('forma_pagamento')->nullable();
        $table->timestamp('data_venda')->useCurrent();
        $table->foreign('loja_id')->references('id')->on('lojas')->onDelete('cascade');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
