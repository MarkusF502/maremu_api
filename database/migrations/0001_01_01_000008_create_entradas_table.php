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
    Schema::create('entradas', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('loja_id');
        $table->uuid('produto_id');
        $table->integer('quantidade');
        $table->decimal('custo_unitario', 10, 2);
        $table->decimal('frete_entrada', 10, 2)->default(0);
        $table->string('fornecedor')->nullable();
        $table->timestamp('data_entrada')->useCurrent();
        $table->foreign('loja_id')->references('id')->on('lojas')->onDelete('cascade');
        $table->foreign('produto_id')->references('id')->on('produtos')->onDelete('cascade');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entradas');
    }
};
