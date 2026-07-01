<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // create_lojas_table
public function up(): void
{
    Schema::create('lojas', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->uuid('user_id');
        $table->string('nome');
        $table->enum('regime_tributario', [
            'simples_nacional', 'lucro_presumido', 'lucro_real'
        ]);
        $table->decimal('aliquota_efetiva', 5, 2);
        $table->enum('aliquota_origem', [
            'estimado_pelo_sistema', 'confirmado_pelo_lojista', 'editado_pelo_lojista'
        ])->default('estimado_pelo_sistema');
        $table->decimal('faturamento_medio_mensal', 10, 2)->nullable();
        $table->decimal('custo_fixo_mensal', 10, 2)->nullable();
        $table->enum('custo_fixo_origem', [
            'estimado_pelo_sistema', 'confirmado_pelo_lojista', 'editado_pelo_lojista'
        ])->default('estimado_pelo_sistema');
        $table->decimal('margem_lucro_desejada', 5, 2);
        $table->enum('posicionamento', ['popular', 'medio', 'premium']);
        $table->decimal('volume_vendas_esperado', 10, 2)->nullable();
        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lojas');
    }
};
