<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lojas', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            // Identificação
            $table->string('nome');
            $table->enum('posicionamento', ['popular', 'medio', 'premium']);
            $table->enum('regime_tributario', ['simples_nacional', 'lucro_presumido', 'lucro_real']);

            // Dados financeiros da loja (alimentam o PricingEngine)
            $table->decimal('faturamento_medio_mensal', 12, 2);
            $table->decimal('custo_fixo_mensal', 12, 2);
            $table->decimal('margem_lucro_desejada', 5, 4); // decimal ex: 0.3500
            $table->decimal('aliquota_efetiva', 5, 4);
            $table->integer('volume_vendas_esperado');

            // Metadados de origem por campo sensível
            $table->enum('custo_fixo_origem', ['estimado_pelo_sistema', 'confirmado_pelo_lojista', 'editado_pelo_lojista'])
                  ->default('estimado_pelo_sistema');
            $table->enum('aliquota_origem', ['estimado_pelo_sistema', 'confirmado_pelo_lojista', 'editado_pelo_lojista'])
                  ->default('estimado_pelo_sistema');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lojas');
    }
};