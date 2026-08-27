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
        Schema::table('logs_sugestao_ia', function (Blueprint $table) {
            // Qual provedor de IA gerou a sugestão (ver App\Services\PrecificacaoIaInterface).
            // Nullable para não quebrar logs já existentes, gerados antes desta coluna existir.
            $table->string('provedor_ia')->nullable()->after('payload_enviado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('logs_sugestao_ia', function (Blueprint $table) {
            $table->dropColumn('provedor_ia');
        });
    }
};
