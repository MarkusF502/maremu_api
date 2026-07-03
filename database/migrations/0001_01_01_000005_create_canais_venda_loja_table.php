<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canais_venda_loja', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loja_id')->constrained('lojas')->cascadeOnDelete();

            $table->enum('canal', ['loja_fisica', 'instagram_whatsapp', 'marketplace']);
            $table->decimal('taxa_percentual', 5, 4); // ex: 0.1500 = 15%
            $table->enum('taxa_origem', ['estimado_pelo_sistema', 'confirmado_pelo_lojista', 'editado_pelo_lojista'])
                  ->default('estimado_pelo_sistema');
            $table->boolean('ativo')->default(true);

            $table->timestamps();

            // Uma loja não pode ter o mesmo canal duplicado
            $table->unique(['loja_id', 'canal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canais_venda_loja');
    }
};