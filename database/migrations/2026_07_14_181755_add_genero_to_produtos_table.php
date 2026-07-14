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
        Schema::table('produtos', function (Blueprint $table) {
            // Substitua 'nome' pelo nome da coluna após a qual você quer que o 'genero' fique.
            // Se preferir limitar as opções, você pode trocar string() por enum('genero', ['masculino', 'feminino', 'unissex', 'infantil'])
            $table->string('genero')->nullable()->after('nome');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('produtos', function (Blueprint $table) {
            $table->dropColumn('genero');
        });
    }
};