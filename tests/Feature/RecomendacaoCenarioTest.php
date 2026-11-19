<?php

namespace Tests\Feature;

use App\Jobs\AgregarMetricasCategoria;
use App\Models\Categoria;
use App\Models\LogRecomendacaoCenario;
use App\Models\LogsSugestaoIa;
use App\Models\Loja;
use App\Models\Produto;
use App\Models\VarianteProduto;
use App\Services\PrecificacaoIaInterface;
use App\Services\RecomendacaoCenarioDados;
use App\Support\GiroProduto;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Recomendação de cenário de ponta a ponta: giro igual ao do job, endpoints,
 * registro em logs_recomendacao_cenario e comando de aceitação.
 *
 * Cenário da categoria Camisetas (giro de referência 10 dias por peça, a
 * mediana que o job grava):
 *   base 1..5  9 peças em 90 dias, giro 10   → venda ideal (razão 1,0)
 *   alta       18 peças, giro 5               → alta demanda (razão 0,5)
 *   lento      6 peças, giro 15               → liquidação pela razão (1,5)
 *   parado     1 peça há 30 dias              → liquidação por dias sem venda
 *   esgotado   estoque zero                   → sem_estoque
 *   poucas     2 peças, última há 3 dias      → dados_insuficientes
 */
class RecomendacaoCenarioTest extends TestCase
{
    use RefreshDatabase;

    private Loja $loja;

    private Categoria $camisetas;

    /** @var array<string, Produto> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00'));
        config(['services.ia_provider' => 'simulado']);

        $this->loja = Loja::factory()->create([
            'aliquota_efetiva' => 0.06,
            'custo_fixo_mensal' => 2_700,
            'volume_vendas_esperado' => 180,
        ]);
        $this->loja->canais()->create([
            'canal' => 'loja_fisica',
            'taxa_percentual' => 0.03,
            'taxa_origem' => 'estimado_pelo_sistema',
            'ativo' => true,
        ]);
        $this->camisetas = Categoria::factory()->create(['loja_id' => $this->loja->id, 'nome' => 'Camisetas']);

        for ($i = 1; $i <= 5; $i++) {
            $this->p["base{$i}"] = $this->produto("Base {$i}", 5, 120, [[1, 3], [20, 3], [50, 3]]);
        }
        $this->p['alta'] = $this->produto('Alta', 5, 120, [[1, 6], [10, 6], [30, 6]]);
        $this->p['lento'] = $this->produto('Lento', 5, 120, [[2, 3], [60, 3]]);
        $this->p['parado'] = $this->produto('Parado', 6, 120, [[30, 1]]);
        $this->p['esgotado'] = $this->produto('Esgotado', 0, 120, [[1, 3], [20, 3], [50, 3]]);
        $this->p['poucas'] = $this->produto('Poucas', 5, 120, [[3, 2]]);

        AgregarMetricasCategoria::dispatchSync();

        Sanctum::actingAs($this->loja->user);
    }

    // ─── giro ────────────────────────────────────────────────────────────

    public function test_giro_de_referencia_e_a_mediana_gravada_pelo_job(): void
    {
        $this->assertEquals(10.0, (float) DB::table('metricas_categoria_loja')
            ->where('categoria_id', $this->camisetas->id)->value('giro_medio_dias'));

        $this->recomendacao('base1')->assertJsonPath('sinais.giro_referencia_dias', 10);
    }

    public function test_formula_de_giro_do_produto_e_a_mesma_do_job(): void
    {
        // Dias observados com o dia do cadastro incluído, teto de 90 e giros
        // quebrados: 41 ÷ 3, 11 ÷ 4 e 90 ÷ 7. A mediana do job é a do meio.
        $outra = Categoria::factory()->create(['loja_id' => $this->loja->id, 'nome' => 'Vestidos']);
        $a = $this->produto('A', 3, 40, [[1, 3]], categoria: $outra);
        $b = $this->produto('B', 3, 10, [[1, 4]], categoria: $outra);
        $c = $this->produto('C', 3, 200, [[1, 7]], categoria: $outra);

        AgregarMetricasCategoria::dispatchSync();

        $hoje = CarbonImmutable::now()->startOfDay();
        $this->assertEqualsWithDelta(41 / 3, GiroProduto::dias($a->data_cadastro, 3, $hoje, 90), 1e-9);
        $this->assertEqualsWithDelta(11 / 4, GiroProduto::dias($b->data_cadastro, 4, $hoje, 90), 1e-9);
        $this->assertEqualsWithDelta(90 / 7, GiroProduto::dias($c->data_cadastro, 7, $hoje, 90), 1e-9);

        $doJob = (float) DB::table('metricas_categoria_loja')->where('categoria_id', $outra->id)->value('giro_medio_dias');

        // O produto mediano, medido pela recomendação, dá o mesmo número do
        // job. (Com só 3 produtos vendendo, a categoria não tem referência
        // própria; os sinais são lidos direto da avaliação.)
        $this->assertSame(round(90 / 7, 2), $doJob);
        $sinais = app(RecomendacaoCenarioDados::class)->avaliarProduto($c->fresh())['sinais'];
        $this->assertSame($doJob, $sinais['giro_produto_dias']);
    }

    // ─── GET /produtos/{produto}/recomendacao-cenario ────────────────────

    public function test_endpoint_devolve_cada_regra(): void
    {
        $this->recomendacao('base1')->assertJsonPath('cenario_recomendado', 'ideal')
            ->assertJsonPath('origem_recomendacao', 'historico_proprio')
            ->assertJsonPath('versao_regras', 'v1');
        $this->recomendacao('alta')->assertJsonPath('cenario_recomendado', 'alta_demanda')
            ->assertJsonPath('sinais.razao_giro_produto', 0.5);
        $this->recomendacao('lento')->assertJsonPath('cenario_recomendado', 'liquidacao')
            ->assertJsonPath('sinais.razao_giro_produto', 1.5);
        $this->recomendacao('parado')->assertExactJson([
            'disponivel' => true,
            'cenario_recomendado' => 'liquidacao',
            'origem_recomendacao' => 'historico_proprio',
            'motivo' => 'Este produto está há 30 dias sem venda; nesta categoria, sua loja vende uma peça a cada 10 dias.',
            'sinais' => [
                'dias_sem_venda' => 30,
                'unidades_vendidas_90d' => 1,
                'giro_produto_dias' => null,
                'giro_referencia_dias' => 10,
                'razao_giro_produto' => null,
            ],
            'versao_regras' => 'v1',
        ]);
        $this->recomendacao('esgotado')->assertExactJson(['disponivel' => false, 'motivo_indisponivel' => 'sem_estoque']);
        $this->recomendacao('poucas')->assertExactJson(['disponivel' => false, 'motivo_indisponivel' => 'dados_insuficientes']);
    }

    public function test_categoria_sem_historico_nem_camada_3_fica_sem_referencia(): void
    {
        $nova = Categoria::factory()->create(['loja_id' => $this->loja->id, 'nome' => 'Nova']);
        $produto = $this->produto('Sozinho', 4, 100, [[70, 1]], categoria: $nova);

        $this->getJson("/api/produtos/{$produto->id}/recomendacao-cenario")
            ->assertOk()
            ->assertExactJson(['disponivel' => false, 'motivo_indisponivel' => 'sem_referencia']);
    }

    public function test_produto_de_outra_loja_responde_404(): void
    {
        $outraLoja = Loja::factory()->create();
        $alheio = Produto::factory()->create([
            'loja_id' => $outraLoja->id,
            'categoria_id' => Categoria::factory()->create(['loja_id' => $outraLoja->id])->id,
        ]);

        $this->getJson("/api/produtos/{$alheio->id}/recomendacao-cenario")->assertNotFound();
        $this->getJson('/api/produtos/'.Str::uuid().'/recomendacao-cenario')->assertNotFound();
    }

    // ─── listagem ────────────────────────────────────────────────────────

    public function test_listagem_traz_a_recomendacao_resumida(): void
    {
        $produtos = $this->listagem();

        // Todos os produtos do cenário são manuais: toda recomendação difere.
        $this->assertSame(['cenario_recomendado' => 'alta_demanda', 'difere_do_atual' => true], $produtos['Alta']['recomendacao']);
        $this->assertSame(['cenario_recomendado' => 'liquidacao', 'difere_do_atual' => true], $produtos['Parado']['recomendacao']);
        $this->assertNull($produtos['Esgotado']['recomendacao']);
        $this->assertNull($produtos['Poucas']['recomendacao']);
    }

    public function test_difere_do_atual_le_o_tipo_do_cenario_no_log(): void
    {
        // Ordem convencional: cenario_2 é ideal, e base1 é recomendado ideal.
        $this->confirmado('base1', 'cenario_2', $this->cenarios('liquidacao', 'ideal', 'alta_demanda'));

        // A IA trocou os ids: cenario_1 veio como alta demanda. Pela posição
        // seria liquidação; pelo log é alta demanda, a mesma recomendada.
        $this->confirmado('alta', 'cenario_1', $this->cenarios('alta_demanda', 'ideal', 'liquidacao'));

        // ia_cenario_3 sem log nenhum: cenário em uso desconhecido, difere.
        $this->p['lento']->update(['preco_origem' => 'ia_cenario_3']);

        // Dois logs com cenario_2: vale o mais recente, em que era liquidação.
        $this->confirmado('base2', 'cenario_2', $this->cenarios('liquidacao', 'ideal', 'alta_demanda'), minutosAtras: 60);
        $this->confirmado('base2', 'cenario_2', $this->cenarios('ideal', 'liquidacao', 'alta_demanda'));

        // preco_origem nulo também difere.
        $this->p['base3']->update(['preco_origem' => null]);

        $produtos = $this->listagem();

        $this->assertFalse($produtos['Base 1']['recomendacao']['difere_do_atual']);
        $this->assertFalse($produtos['Alta']['recomendacao']['difere_do_atual']);
        $this->assertTrue($produtos['Lento']['recomendacao']['difere_do_atual']);
        $this->assertTrue($produtos['Base 2']['recomendacao']['difere_do_atual']);
        $this->assertTrue($produtos['Base 3']['recomendacao']['difere_do_atual']);
        $this->assertTrue($produtos['Base 4']['recomendacao']['difere_do_atual']); // manual
    }

    public function test_listagem_nao_faz_uma_consulta_por_produto(): void
    {
        // Uma categoria sem histórico próprio força o caminho da camada 3.
        $nova = Categoria::factory()->create(['loja_id' => $this->loja->id, 'nome' => 'Nova']);
        $this->produto('Nova 1', 3, 50, [[2, 1]], categoria: $nova);

        $antes = $this->contarConsultas(fn () => $this->getJson('/api/produtos')->assertOk());

        for ($i = 0; $i < 15; $i++) {
            $this->produto("Extra {$i}", 4, 100, [[5, 3]]);
            $this->produto("Nova extra {$i}", 2, 30, [[1, 1]], categoria: $nova);
        }

        $depois = $this->contarConsultas(fn () => $this->getJson('/api/produtos')->assertOk());

        $this->assertCount(41, $this->listagem());
        $this->assertSame($antes, $depois, 'O número de consultas da listagem não pode crescer com os produtos.');
    }

    public function test_get_e_listagem_nao_gravam_log(): void
    {
        $this->recomendacao('parado');
        $this->getJson('/api/produtos')->assertOk();

        $this->assertDatabaseCount('logs_recomendacao_cenario', 0);
    }

    // ─── POST /precificacao/sugerir/{produto} ────────────────────────────

    public function test_sugerir_devolve_e_grava_a_recomendacao(): void
    {
        $resposta = $this->postJson("/api/precificacao/sugerir/{$this->p['parado']->id}")->assertOk();

        // O que já existia continua igual.
        $resposta->assertJsonStructure(['log_id', 'cenarios', 'composicao', 'recomendacao']);
        $this->assertCount(3, $resposta->json('cenarios'));

        // Mesmo formato e conteúdo do GET.
        $this->assertSame($this->recomendacao('parado')->json(), $resposta->json('recomendacao'));

        $this->assertDatabaseCount('logs_recomendacao_cenario', 1);
        $log = LogRecomendacaoCenario::sole();
        $this->assertSame($this->p['parado']->id, $log->produto_id);
        $this->assertSame($this->loja->id, $log->loja_id);
        $this->assertSame($resposta->json('log_id'), $log->log_sugestao_ia_id);
        $this->assertSame('liquidacao', $log->cenario_recomendado);
        $this->assertSame('historico_proprio', $log->origem_recomendacao);
        $this->assertNull($log->motivo_indisponivel);
        $this->assertSame(30, $log->sinais['dias_sem_venda']);
        $this->assertSame('v1', $log->versao_regras);
    }

    public function test_sugerir_grava_tambem_quando_indisponivel(): void
    {
        $this->postJson("/api/precificacao/sugerir/{$this->p['esgotado']->id}")
            ->assertOk()
            ->assertJsonPath('recomendacao', ['disponivel' => false, 'motivo_indisponivel' => 'sem_estoque']);

        $log = LogRecomendacaoCenario::sole();
        $this->assertNull($log->cenario_recomendado);
        $this->assertSame('sem_estoque', $log->motivo_indisponivel);
        $this->assertSame(9, $log->sinais['unidades_vendidas_90d']);
    }

    public function test_sugerir_que_falha_na_ia_nao_grava_recomendacao(): void
    {
        $this->mock(PrecificacaoIaInterface::class, function ($mock) {
            $mock->shouldReceive('identificador')->andReturn('fake');
            $mock->shouldReceive('sugerirCenarios')->andThrow(new RuntimeException('fora do ar'));
        });

        $this->postJson("/api/precificacao/sugerir/{$this->p['parado']->id}")->assertStatus(502);

        $this->assertDatabaseCount('logs_recomendacao_cenario', 0);
    }

    // ─── comando de aceitação ────────────────────────────────────────────

    public function test_comando_calcula_a_taxa_de_aceitacao(): void
    {
        $normal = $this->cenarios('liquidacao', 'ideal', 'alta_demanda');
        $trocada = $this->cenarios('alta_demanda', 'ideal', 'liquidacao');

        // historico_proprio, loja do teste
        $this->rodada('base1', 'liquidacao', 'cenario_1', $normal);          // coincide
        $this->rodada('base2', 'alta_demanda', 'cenario_1', $trocada);       // coincide (ids trocados)
        $this->rodada('base3', 'ideal', 'cenario_3', $normal);               // não coincide
        $this->rodada('base4', 'ideal', 'manual', $normal);                  // manual: não coincide
        $this->rodada('base5', 'liquidacao', null, $normal);                 // sem escolha
        $this->rodada('esgotado', null, 'cenario_1', $normal);               // indisponível
        // lojas_similares
        $this->rodada('alta', 'ideal', 'cenario_2', $normal, origem: 'lojas_similares'); // coincide

        // Outra loja: só entra sem --loja.
        $outra = Loja::factory()->create();
        $alheio = Produto::factory()->create([
            'loja_id' => $outra->id,
            'categoria_id' => Categoria::factory()->create(['loja_id' => $outra->id])->id,
        ]);
        $this->rodada($alheio, 'ideal', 'cenario_2', $normal);              // coincide

        $cabecalho = ['versão', 'origem', 'registros', 'recomendações', 'com escolha', 'coincidiram', 'aceitação'];

        $this->artisan('recomendacao:aceitacao', ['--loja' => $this->loja->id])
            ->expectsTable($cabecalho, [
                ['v1', 'historico_proprio', 6, 5, 4, 2, '50,0%'],
                ['v1', 'lojas_similares', 1, 1, 1, 1, '100,0%'],
            ])
            ->assertSuccessful();

        $this->artisan('recomendacao:aceitacao')
            ->expectsTable($cabecalho, [
                ['v1', 'historico_proprio', 7, 6, 5, 3, '60,0%'],
                ['v1', 'lojas_similares', 1, 1, 1, 1, '100,0%'],
            ])
            ->assertSuccessful();
    }

    public function test_comando_recusa_loja_inexistente(): void
    {
        $this->artisan('recomendacao:aceitacao', ['--loja' => (string) Str::uuid()])->assertFailed();
    }

    // ─── apoio ───────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{0: int, 1: int}>  $vendas  [dias atrás, unidades]
     */
    private function produto(string $nome, int $estoque, int $cadastroDiasAtras, array $vendas, ?Categoria $categoria = null): Produto
    {
        $produto = Produto::create([
            'loja_id' => $this->loja->id,
            'categoria_id' => ($categoria ?? $this->camisetas)->id,
            'nome' => $nome,
            'custo_aquisicao' => 25.00,
            'frete_entrada_unitario' => 0,
            'preco_piso' => 43.96,
            'preco_venda_atual' => 60.00,
            'preco_origem' => 'manual',
            'status' => 'ativo',
            'data_cadastro' => now()->subDays($cadastroDiasAtras),
        ]);

        VarianteProduto::create([
            'produto_id' => $produto->id,
            'tamanho' => 'M',
            'quantidade_estoque' => $estoque,
            'estoque_minimo_alerta' => 1,
        ]);

        foreach ($vendas as [$diasAtras, $unidades]) {
            $pedidoId = (string) Str::uuid();
            $quando = now()->subDays($diasAtras);
            DB::table('pedidos')->insert([
                'id' => $pedidoId,
                'loja_id' => $this->loja->id,
                'canal_venda' => 'loja_fisica',
                'valor_total' => 60 * $unidades,
                'data_venda' => $quando,
                'created_at' => $quando,
                'updated_at' => $quando,
            ]);
            DB::table('itens_pedido')->insert([
                'id' => (string) Str::uuid(),
                'pedido_id' => $pedidoId,
                'produto_id' => $produto->id,
                'quantidade' => $unidades,
                'preco_unitario_vendido' => 60,
                'custo_unitario_no_momento' => 25,
                'desconto_aplicado' => 0,
                'created_at' => $quando,
                'updated_at' => $quando,
            ]);
        }

        return $produto;
    }

    private function recomendacao(string $chave)
    {
        return $this->getJson("/api/produtos/{$this->p[$chave]->id}/recomendacao-cenario")->assertOk();
    }

    /** @return array<string, array<string, mixed>> produtos da listagem, pelo nome */
    private function listagem(): array
    {
        return collect($this->getJson('/api/produtos')->assertOk()->json('produtos'))->keyBy('nome')->all();
    }

    private function cenarios(string $tipo1, string $tipo2, string $tipo3): array
    {
        return [
            ['id' => 'cenario_1', 'tipo' => $tipo1, 'margem_lucro_percentual' => 0.1, 'explicacao' => 'a', 'preco_sugerido' => 50.0],
            ['id' => 'cenario_2', 'tipo' => $tipo2, 'margem_lucro_percentual' => 0.2, 'explicacao' => 'b', 'preco_sugerido' => 60.0],
            ['id' => 'cenario_3', 'tipo' => $tipo3, 'margem_lucro_percentual' => 0.3, 'explicacao' => 'c', 'preco_sugerido' => 70.0],
        ];
    }

    /** Rodada de sugestão confirmada, como faz POST /precificacao/confirmar. */
    private function confirmado(string $chave, string $cenarioId, array $cenarios, int $minutosAtras = 0): void
    {
        $produto = $this->p[$chave];
        $quando = now()->subMinutes($minutosAtras);

        $log = LogsSugestaoIa::create([
            'produto_id' => $produto->id,
            'payload_enviado' => [],
            'cenarios_retornados' => $cenarios,
            'cenario_escolhido' => $cenarioId,
        ]);
        $log->forceFill(['created_at' => $quando, 'updated_at' => $quando])->saveQuietly();

        $produto->update(['preco_origem' => 'ia_'.$cenarioId]);
    }

    private function rodada(string|Produto $produto, ?string $recomendado, ?string $escolhido, array $cenarios, string $origem = 'historico_proprio'): void
    {
        $produto = is_string($produto) ? $this->p[$produto] : $produto;

        $log = LogsSugestaoIa::create([
            'produto_id' => $produto->id,
            'payload_enviado' => [],
            'cenarios_retornados' => $cenarios,
            'cenario_escolhido' => $escolhido,
        ]);

        LogRecomendacaoCenario::create([
            'produto_id' => $produto->id,
            'loja_id' => $produto->loja_id,
            'log_sugestao_ia_id' => $log->id,
            'cenario_recomendado' => $recomendado,
            'origem_recomendacao' => $origem,
            'motivo_indisponivel' => $recomendado === null ? 'sem_estoque' : null,
            'sinais' => [],
            'versao_regras' => 'v1',
        ]);
    }

    private function contarConsultas(callable $acao): int
    {
        $total = 0;
        DB::listen(function () use (&$total) {
            $total++;
        });

        $antes = $total;
        $acao();

        return $total - $antes;
    }
}
