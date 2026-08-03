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
        Schema::table('metricas_categoria_loja', function (Blueprint $table) {
            $table->boolean('desatualizada')->default(false)->after('candidatos_liquidacao');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('metricas_categoria_loja', function (Blueprint $table) {
            $table->dropColumn('desatualizada');
        });
    }
};