<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca as lojas de demonstração. A camada 3 nunca mistura os dois mundos:
     * loja real só é comparada com loja real, loja demo só com loja demo.
     *
     * O campo fica fora de `$fillable` de propósito — nenhuma rota pode criar
     * ou virar uma loja demo; só seeders e testes atribuem o valor direto.
     */
    public function up(): void
    {
        Schema::table('lojas', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('posicionamento');

            // A camada 3 filtra sempre pelos dois campos juntos.
            $table->index(['is_demo', 'posicionamento'], 'ix_lojas_demo_posicionamento');
        });
    }

    public function down(): void
    {
        Schema::table('lojas', function (Blueprint $table) {
            $table->dropIndex('ix_lojas_demo_posicionamento');
            $table->dropColumn('is_demo');
        });
    }
};
