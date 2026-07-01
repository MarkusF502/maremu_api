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
    Schema::create('canais_venda_loja', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('loja_id');
        $table->enum('canal', [
            'loja_fisica', 'instagram_whatsapp', 'marketplace', 'outro'
        ]);
        $table->decimal('taxa_percentual', 5, 2);
        $table->enum('taxa_origem', [
            'estimado_pelo_sistema', 'confirmado_pelo_lojista', 'editado_pelo_lojista'
        ])->default('estimado_pelo_sistema');
        $table->boolean('ativo')->default(true);
        $table->foreign('loja_id')->references('id')->on('lojas')->onDelete('cascade');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canais_venda_loja');
    }
};
