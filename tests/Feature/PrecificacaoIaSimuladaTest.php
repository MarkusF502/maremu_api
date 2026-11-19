<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Loja;
use App\Models\Produto;
use App\Services\PrecificacaoIaInterface;
use App\Services\PrecificacaoIaSimuladaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * IA_PROVIDER=simulado: margens fixas, sem chamada externa, nunca em produção.
 */
class PrecificacaoIaSimuladaTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_resolve_o_provedor_simulado(): void
    {
        config(['services.ia_provider' => 'simulado']);

        $servico = $this->app->make(PrecificacaoIaInterface::class);

        $this->assertInstanceOf(PrecificacaoIaSimuladaService::class, $servico);
        $this->assertSame('simulado', $servico->identificador());
    }

    public function test_devolve_tres_cenarios_fixos_marcados_como_simulados(): void
    {
        $servico = new PrecificacaoIaSimuladaService;

        $primeira = $servico->sugerirCenarios(['qualquer' => 'payload']);
        $segunda = $servico->sugerirCenarios([]);

        $this->assertSame($primeira, $segunda);
        $this->assertSame(['cenario_1', 'cenario_2', 'cenario_3'], array_column($primeira['cenarios'], 'id'));
        $this->assertSame(['liquidacao', 'ideal', 'alta_demanda'], array_column($primeira['cenarios'], 'tipo'));

        foreach ($primeira['cenarios'] as $cenario) {
            $this->assertStringStartsWith('[simulado]', $cenario['explicacao']);
            $this->assertGreaterThan(0.0, $cenario['margem_lucro_percentual']);
            $this->assertLessThanOrEqual(0.80, $cenario['margem_lucro_percentual']);
        }
    }

    public function test_recusa_rodar_em_producao(): void
    {
        config(['services.ia_provider' => 'simulado']);
        $this->app['env'] = 'production';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('production');

            $this->app->make(PrecificacaoIaInterface::class);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_sugerir_funciona_de_ponta_a_ponta_com_o_simulado(): void
    {
        config(['services.ia_provider' => 'simulado']);

        $loja = Loja::factory()->create([
            'aliquota_efetiva' => 0.06,
            'custo_fixo_mensal' => 2_700,
            'volume_vendas_esperado' => 180,
        ]);
        $loja->canais()->create([
            'canal' => 'loja_fisica',
            'taxa_percentual' => 0.03,
            'taxa_origem' => 'estimado_pelo_sistema',
            'ativo' => true,
        ]);
        $categoria = Categoria::factory()->create(['loja_id' => $loja->id]);
        $produto = Produto::factory()->create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'custo_aquisicao' => 25.00,
            'frete_entrada_unitario' => 0,
            'preco_piso' => 43.96,
        ]);

        Sanctum::actingAs($loja->user);

        $resposta = $this->postJson("/api/precificacao/sugerir/{$produto->id}")->assertOk();

        $this->assertCount(3, $resposta->json('cenarios'));
        $this->assertDatabaseHas('logs_sugestao_ia', [
            'id' => $resposta->json('log_id'),
            'provedor_ia' => 'simulado',
        ]);
    }
}
