<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Loja;
use App\Models\Produto;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * PrecificacaoLiquidacaoDivergenciaTest
 *
 * Cobre o ponto central da arquitetura decidida (ver Relatório de Testes de
 * Variância §6 e PrecificacaoController::calcularPrecosDosCenarios): o preço
 * final do cenário de liquidação NUNCA depende da resposta da IA, mesmo
 * quando ela "confirma" um valor — nem para conferir se ela concordou. O
 * GeminiService é mockado para simular exatamente o caso que o texto de
 * validação da IA (responseSchema) sozinho não pega: um valor plausível
 * (dentro de [0.0, 0.80]) mas divergente de instrucao_liquidacao.margem_definida.
 *
 * Ajuste os nomes de model/factory abaixo (Loja, Produto, canal de venda)
 * conforme o schema real do projeto, caso divirjam do que foi usado aqui —
 * foram inferidos a partir de PrecificacaoPayloadService e
 * TestarVarianciaPrecificacao.php.
 */
class PrecificacaoLiquidacaoDivergenciaTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Loja $loja;
    private Produto $produto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // posicionamento 'popular' + sem MetricasCategoriaLoja (cold start) →
        // instrucao_liquidacao cai no fallback_posicionamento, margem_definida
        // = 0.10 (ver MargemLiquidacaoService::MARGEM_FALLBACK). Determinístico
        // e não depende de nenhuma fixture de camada_4 para o teste funcionar.
        $this->loja = Loja::factory()->create([
            'user_id'                 => $this->user->id,
            'posicionamento'          => 'popular',
            'regime_tributario'       => 'simples_nacional',
            'faturamento_medio_mensal' => 20000,
            'custo_fixo_mensal'       => 5000,
            'margem_lucro_desejada'   => 0.30,
            'aliquota_efetiva'        => 0.109,
            'volume_vendas_esperado'  => 120,
        ]);

        // Canal ativo — necessário para PrecificacaoController::taxaCanalAplicavel().
        $this->loja->canaisAtivos()->create([
            'canal'           => 'loja_fisica',
            'taxa_percentual' => 0.03,
            'taxa_origem'     => 'estimado_pelo_sistema',
        ]);

        $categoria = Categoria::factory()->create([
            'loja_id' => $this->loja->id,
            'nome'    => 'calças',
        ]);

        $this->produto = Produto::factory()->create([
            'loja_id'                 => $this->loja->id,
            'categoria_id'            => $categoria->id,
            'nome'                    => 'calça cargo',
            'custo_aquisicao'         => 40.00,
            'frete_entrada_unitario'  => 10.00,
            'preco_venda_atual'       => 100.00,
            'preco_piso'              => 65.00,
            'status'                  => 'ativo',
        ]);
    }

    /**
     * A IA "confirma" — mas erradamente — uma margem de liquidação diferente
     * da que o sistema definiu. O preço final deve ignorar esse valor por
     * completo, e um warning de prompt drift deve ser logado.
     */
    public function test_preco_de_liquidacao_ignora_margem_divergente_ecoada_pela_ia(): void
    {
        Log::spy();

        // margem_definida esperada = 0.10 (fallback 'popular'). A IA devolve
        // 0.20 para o cenário de liquidação — divergente, mas ainda dentro
        // do range [0.0, 0.80] aceito pelo responseSchema, então não seria
        // pego pela validação estrutural do GeminiService.
        $this->mock(GeminiService::class, function ($mock) {
            $mock->shouldReceive('sugerirCenarios')
                ->once()
                ->andReturn([
                    'cenarios' => [
                        [
                            'id' => 'cenario_1', 'tipo' => 'liquidacao',
                            'margem_lucro_percentual' => 0.20, // divergente de 0.10
                            'explicacao' => 'Margem reduzida para girar o estoque parado.',
                        ],
                        [
                            'id' => 'cenario_2', 'tipo' => 'ideal',
                            'margem_lucro_percentual' => 0.30,
                            'explicacao' => 'Margem equilibrada, alinhada ao posicionamento da loja.',
                        ],
                        [
                            'id' => 'cenario_3', 'tipo' => 'alta_demanda',
                            'margem_lucro_percentual' => 0.45,
                            'explicacao' => 'Margem elevada para produto de alta demanda.',
                        ],
                    ],
                ]);
        });

        $response = $this->actingAs($this->user)
            ->postJson("/api/precificacao/sugerir/{$this->produto->id}");

        $response->assertOk();

        $cenarioLiquidacao = collect($response->json('cenarios'))->firstWhere('tipo', 'liquidacao');

        // A margem efetivamente aplicada é a definida pelo sistema (0.10),
        // não a ecoada pela IA (0.20) — e o preco_sugerido reflete isso.
        $this->assertEquals(0.10, $cenarioLiquidacao['margem_aplicada']);
        $this->assertNotEquals(0.20, $cenarioLiquidacao['margem_aplicada']);

        $precoComMargemCorreta = round(
            (($this->produto->custo_aquisicao + $this->produto->frete_entrada_unitario)
                + ($this->loja->custo_fixo_mensal / $this->loja->volume_vendas_esperado))
            / (1 - ($this->loja->aliquota_efetiva + 0.03 + 0.10)),
            2
        );
        $this->assertEquals($precoComMargemCorreta, $cenarioLiquidacao['preco_sugerido']);

        // O warning de prompt drift deve ter sido logado, citando os dois valores.
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $mensagem, array $contexto) {
                return str_contains($mensagem, 'divergente')
                    && $contexto['margem_ecoada'] === 0.20
                    && $contexto['margem_definida'] === 0.10;
            });
    }

    /**
     * Caso feliz: a IA ecoa corretamente o valor definido pelo sistema.
     * Nenhum warning deve ser logado.
     */
    public function test_preco_de_liquidacao_nao_loga_warning_quando_ia_ecoa_corretamente(): void
    {
        Log::spy();

        $this->mock(GeminiService::class, function ($mock) {
            $mock->shouldReceive('sugerirCenarios')
                ->once()
                ->andReturn([
                    'cenarios' => [
                        [
                            'id' => 'cenario_1', 'tipo' => 'liquidacao',
                            'margem_lucro_percentual' => 0.10, // eco correto
                            'explicacao' => 'Margem reduzida para girar o estoque parado.',
                        ],
                        [
                            'id' => 'cenario_2', 'tipo' => 'ideal',
                            'margem_lucro_percentual' => 0.30,
                            'explicacao' => 'Margem equilibrada, alinhada ao posicionamento da loja.',
                        ],
                        [
                            'id' => 'cenario_3', 'tipo' => 'alta_demanda',
                            'margem_lucro_percentual' => 0.45,
                            'explicacao' => 'Margem elevada para produto de alta demanda.',
                        ],
                    ],
                ]);
        });

        $response = $this->actingAs($this->user)
            ->postJson("/api/precificacao/sugerir/{$this->produto->id}");

        $response->assertOk();

        $cenarioLiquidacao = collect($response->json('cenarios'))->firstWhere('tipo', 'liquidacao');
        $this->assertEquals(0.10, $cenarioLiquidacao['margem_aplicada']);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * cenario_ideal e cenario_alta_demanda continuam usando a margem que a
     * IA escolheu livremente (Condição B) — só o cenário de liquidação passa
     * pelo desvio determinístico.
     */
    public function test_cenarios_ideal_e_alta_demanda_usam_margem_da_ia_sem_alteracao(): void
    {
        $this->mock(GeminiService::class, function ($mock) {
            $mock->shouldReceive('sugerirCenarios')
                ->once()
                ->andReturn([
                    'cenarios' => [
                        [
                            'id' => 'cenario_1', 'tipo' => 'liquidacao',
                            'margem_lucro_percentual' => 0.10,
                            'explicacao' => 'Margem reduzida.',
                        ],
                        [
                            'id' => 'cenario_2', 'tipo' => 'ideal',
                            'margem_lucro_percentual' => 0.28,
                            'explicacao' => 'Margem equilibrada.',
                        ],
                        [
                            'id' => 'cenario_3', 'tipo' => 'alta_demanda',
                            'margem_lucro_percentual' => 0.50,
                            'explicacao' => 'Margem elevada.',
                        ],
                    ],
                ]);
        });

        $response = $this->actingAs($this->user)
            ->postJson("/api/precificacao/sugerir/{$this->produto->id}");

        $response->assertOk();

        $cenarios = collect($response->json('cenarios'));
        $this->assertEquals(0.28, $cenarios->firstWhere('tipo', 'ideal')['margem_aplicada']);
        $this->assertEquals(0.50, $cenarios->firstWhere('tipo', 'alta_demanda')['margem_aplicada']);
    }
}