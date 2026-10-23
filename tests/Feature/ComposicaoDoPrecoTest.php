<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Loja;
use App\Models\Produto;
use App\Services\PrecificacaoIaInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Composição do preço em POST /api/precificacao/sugerir/{produto}.
 *
 * O frontend usa esses quatro números para abrir qualquer preço P em impostos,
 * taxas e lucro, e assim diagnosticar a distância entre o preço que os custos
 * pedem e a faixa de mercado da camada 3. O sistema não ajusta preço nenhum
 * para caber na faixa, e a faixa não entra no prompt da IA.
 */
class ComposicaoDoPrecoTest extends TestCase
{
    use RefreshDatabase;

    private Loja $loja;

    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        // Custo fixo por peça de R$ 15,00 (2.700 ÷ 180) e alíquota de 6%.
        $this->loja = Loja::factory()->create([
            'posicionamento' => 'medio',
            'regime_tributario' => 'simples_nacional',
            'aliquota_efetiva' => 0.06,
            'custo_fixo_mensal' => 2_700,
            'volume_vendas_esperado' => 180,
            'margem_lucro_desejada' => 0.35,
        ]);

        $categoria = Categoria::factory()->create(['loja_id' => $this->loja->id]);

        $this->produto = Produto::factory()->create([
            'loja_id' => $this->loja->id,
            'categoria_id' => $categoria->id,
            'custo_aquisicao' => 25.00,
            'frete_entrada_unitario' => 0,
            'preco_piso' => 44.20,
            'status' => 'ativo',
        ]);

        Sanctum::actingAs($this->loja->user);
    }

    public function test_composicao_vem_na_resposta_com_sugestao(): void
    {
        $this->canais(['loja_fisica' => 0.030]);
        $this->fingirIa();

        $this->sugerir()
            ->assertOk()
            ->assertJsonStructure([
                'log_id',
                'cenarios',
                'composicao' => ['preco_piso', 'custo_base', 'custo_fixo_unitario', 'percentual_variavel'],
            ])
            // custo base 25,00 + custo fixo 15,00; variável 6% + 3% = 9%
            ->assertJsonPath('composicao.custo_base', 25)
            ->assertJsonPath('composicao.custo_fixo_unitario', 15)
            ->assertJsonPath('composicao.percentual_variavel', 0.09);
    }

    public function test_lucro_no_preco_piso_e_zero(): void
    {
        $this->canais(['loja_fisica' => 0.030]);
        $this->fingirIa();

        $composicao = $this->sugerir()->assertOk()->json('composicao');

        // A conta que o frontend vai fazer, no próprio preço piso.
        $this->assertEqualsWithDelta(0.0, $this->lucro($composicao, $composicao['preco_piso']), 0.01);

        // (25 + 15) ÷ (1 − 0,09) = 43,956... → 43,96
        $this->assertEqualsWithDelta(43.96, $composicao['preco_piso'], 0.01);
    }

    public function test_composicao_respeita_a_taxa_do_canal_mais_caro(): void
    {
        // A precificação usa a maior taxa entre os canais ativos.
        $this->canais(['loja_fisica' => 0.030, 'marketplace' => 0.150]);
        $this->fingirIa();

        $composicao = $this->sugerir()->assertOk()->json('composicao');

        $this->assertEqualsWithDelta(0.21, $composicao['percentual_variavel'], 0.000001);
        // (25 + 15) ÷ (1 − 0,21) = 50,63
        $this->assertEqualsWithDelta(50.63, $composicao['preco_piso'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->lucro($composicao, $composicao['preco_piso']), 0.01);
    }

    public function test_canal_inativo_nao_entra_na_composicao(): void
    {
        $this->canais(['loja_fisica' => 0.030]);
        $this->loja->canais()->create([
            'canal' => 'marketplace',
            'taxa_percentual' => 0.150,
            'taxa_origem' => 'estimado_pelo_sistema',
            'ativo' => false,
        ]);
        $this->fingirIa();

        $composicao = $this->sugerir()->assertOk()->json('composicao');

        $this->assertEqualsWithDelta(0.09, $composicao['percentual_variavel'], 0.000001);
    }

    public function test_piso_da_composicao_e_o_mesmo_dos_cenarios(): void
    {
        $this->canais(['loja_fisica' => 0.035]);
        $this->fingirIa();

        $resposta = $this->sugerir()->assertOk();
        $composicao = $resposta->json('composicao');

        $this->assertNotEmpty($resposta->json('cenarios'));

        foreach ($resposta->json('cenarios') as $cenario) {
            $this->assertSame($composicao['preco_piso'], $cenario['preco_piso_referencia']);
            // Todo cenário fica acima do piso: a margem só se soma ao piso.
            $this->assertGreaterThan($composicao['preco_piso'], $cenario['preco_sugerido']);
        }
    }

    public function test_lucro_cresce_linearmente_acima_do_piso(): void
    {
        $this->canais(['loja_fisica' => 0.030]);
        $this->fingirIa();

        $composicao = $this->sugerir()->assertOk()->json('composicao');
        $piso = $composicao['preco_piso'];

        // Cada real acima do piso rende (1 − percentual_variavel) de lucro.
        $this->assertEqualsWithDelta(
            10 * (1 - $composicao['percentual_variavel']),
            $this->lucro($composicao, $piso + 10),
            0.01,
        );

        // Abaixo do piso o lucro é negativo — é o que acende o alerta.
        $this->assertLessThan(0, $this->lucro($composicao, $piso - 5));
    }

    public function test_composicao_usa_o_custo_do_produto_e_nao_o_piso_gravado(): void
    {
        $this->canais(['loja_fisica' => 0.030]);
        $this->fingirIa();

        // preco_piso da coluna está desatualizado de propósito.
        $this->produto->update(['preco_piso' => 999.99, 'custo_aquisicao' => 40.00, 'frete_entrada_unitario' => 5.00]);

        $composicao = $this->sugerir()->assertOk()->json('composicao');

        $this->assertEqualsWithDelta(45.0, $composicao['custo_base'], 0.01);
        // (45 + 15) ÷ 0,91 = 65,93
        $this->assertEqualsWithDelta(65.93, $composicao['preco_piso'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->lucro($composicao, $composicao['preco_piso']), 0.01);
    }

    // ─── apoio ───────────────────────────────────────────────────────────

    /**
     * Lucro de um preço P pela conta que o frontend faz, só com o que a
     * resposta devolve.
     *
     * @param  array<string, float>  $composicao
     */
    private function lucro(array $composicao, float $preco): float
    {
        $impostosETaxas = $preco * $composicao['percentual_variavel'];

        return $preco - $composicao['custo_base'] - $composicao['custo_fixo_unitario'] - $impostosETaxas;
    }

    /**
     * @param  array<string, float>  $taxas  canal => taxa
     */
    private function canais(array $taxas): void
    {
        foreach ($taxas as $canal => $taxa) {
            $this->loja->canais()->create([
                'canal' => $canal,
                'taxa_percentual' => $taxa,
                'taxa_origem' => 'estimado_pelo_sistema',
                'ativo' => true,
            ]);
        }
    }

    private function fingirIa(): void
    {
        $this->mock(PrecificacaoIaInterface::class, function ($mock) {
            $mock->shouldReceive('identificador')->andReturn('fake');
            $mock->shouldReceive('sugerirCenarios')->once()->andReturn([
                'cenarios' => [
                    ['id' => 'cenario_1', 'tipo' => 'conservador', 'margem_lucro_percentual' => 0.20, 'explicacao' => 'a'],
                    ['id' => 'cenario_2', 'tipo' => 'equilibrado', 'margem_lucro_percentual' => 0.35, 'explicacao' => 'b'],
                    ['id' => 'cenario_3', 'tipo' => 'agressivo', 'margem_lucro_percentual' => 0.50, 'explicacao' => 'c'],
                ],
            ]);
        });
    }

    private function sugerir()
    {
        return $this->postJson("/api/precificacao/sugerir/{$this->produto->id}");
    }
}
