<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Taxonomia fixa de 2 níveis, comum a todas as lojas. É a chave que torna
     * as métricas comparáveis entre lojas (camada 3). O nome livre da categoria
     * da loja continua existindo, só para exibição.
     */
    public function up(): void
    {
        Schema::create('categorias_pai', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('slug')->unique();
            $table->unsignedInteger('google_taxonomy_id')->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('sub_categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('categoria_pai_id')->constrained('categorias_pai')->cascadeOnDelete();
            $table->string('nome');
            $table->string('slug');
            $table->unsignedInteger('google_taxonomy_id')->nullable();
            // "Outros" reúne produtos heterogêneos: nunca é comparada entre lojas.
            $table->boolean('comparavel')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->unique(['categoria_pai_id', 'slug'], 'uk_sub_categorias_pai_slug');
        });

        Schema::table('categorias', function (Blueprint $table) {
            // Nulável: categorias anteriores à taxonomia ficam pendentes de
            // classificação e, enquanto isso, fora da camada 3.
            $table->foreignId('sub_categoria_id')->nullable()->after('loja_id')
                ->constrained('sub_categorias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sub_categoria_id');
        });

        Schema::dropIfExists('sub_categorias');
        Schema::dropIfExists('categorias_pai');
    }
};
