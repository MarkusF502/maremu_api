<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peças complementares (meias, roupas íntimas etc.) são secundárias no
     * catálogo: numa versão futura da precificação não carregam rateio de custo
     * fixo, e o peso delas no catálogo indica lojas especializadas.
     */
    public function up(): void
    {
        Schema::table('sub_categorias', function (Blueprint $table) {
            $table->boolean('complementar')->default(false)->after('comparavel');
        });
    }

    public function down(): void
    {
        Schema::table('sub_categorias', function (Blueprint $table) {
            $table->dropColumn('complementar');
        });
    }
};
