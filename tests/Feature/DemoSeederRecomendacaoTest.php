<?php

namespace Tests\Feature;

use App\Models\Loja;
use App\Models\Produto;
use App\Services\RecomendacaoCenarioDados;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Casos da recomendação de cenário na Loja Demo Apresentação: um produto para
 * cada resultado, de forma determinística, e todos passam pelo sugerir.
 */
class DemoSeederRecomendacaoTest extends TestCase
{
    use RefreshDatabase;

    /** nome do produto => [resultado esperado, origem esperada] */
    private const ESPERADO = [
        'Calcinha básica' => ['ideal', 'historico_proprio'],
        'Calcinha básica lisa' => ['ideal', 'historico_proprio'],
        'Kit de calcinhas' => ['alta_demanda', 'historico_proprio'],
        'Calcinha de renda' => ['liquidacao', 'historico_proprio'],
        'Calcinha estampada' => ['liquidacao', 'historico_proprio'],
        'Calcinha fio' => ['sem_estoque', 'historico_proprio'],
        'Calcinha cós alto' => ['dados_insuficientes', 'historico_proprio'],
        'Calça de linho' => ['liquidacao', 'lojas_similares'],
    ];

    private Loja $consulente;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-23 10:00:00');
        config(['services.ia_provider' => 'simulado']);

        $this->seed(DemoSeeder::class);
        $this->consulente = Loja::where('nome', DemoSeeder::NOME_CONSULENTE)->sole();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cada_caso_de_demonstracao_da_o_resultado_esperado(): void
    {
        $produtos = Produto::with(['categoria', 'variantes'])
            ->where('loja_id', $this->consulente->id)
            ->whereIn('nome', array_keys(self::ESPERADO))
            ->get()
            ->keyBy('nome');

        $this->assertCount(count(self::ESPERADO), $produtos);

        $avaliacoes = app(RecomendacaoCenarioDados::class)->avaliar($this->consulente, $produtos->values());

        foreach (self::ESPERADO as $nome => [$resultado, $origem]) {
            $avaliacao = $avaliacoes[$produtos[$nome]->id];

            $this->assertSame($resultado, $avaliacao['cenario_recomendado'] ?? $avaliacao['motivo_indisponivel'], $nome);
            $this->assertSame($origem, $avaliacao['origem_recomendacao'], $nome);
        }

        // A liquidação da estampada vem dos dias sem venda, não da razão.
        $estampada = $avaliacoes[$produtos['Calcinha estampada']->id];
        $this->assertSame(40, $estampada['sinais']['dias_sem_venda']);
        $this->assertNull($estampada['sinais']['razao_giro_produto']);

        $this->assertStringContainsString('lojas parecidas com a sua', $avaliacoes[$produtos['Calça de linho']->id]['motivo']);
    }

    public function test_na_listagem_so_os_casos_de_demonstracao_mostram_selo(): void
    {
        $produtos = Produto::with(['categoria', 'variantes'])->where('loja_id', $this->consulente->id)->get();
        $resumo = app(RecomendacaoCenarioDados::class)->resumoParaListagem($this->consulente, $produtos);

        $comSelo = $produtos
            ->filter(fn (Produto $p) => $resumo[$p->id]['difere_do_atual'] ?? false)
            ->pluck('nome')->sort()->values()->all();

        $this->assertSame([
            'Calcinha básica',
            'Calcinha básica lisa',
            'Calcinha de renda',
            'Calcinha estampada',
            'Calça de linho',
            'Kit de calcinhas',
        ], $comSelo);
    }

    public function test_resto_do_catalogo_esta_no_cenario_recomendado_pelo_log(): void
    {
        $casos = array_keys(self::ESPERADO);
        $alinhados = Produto::where('loja_id', $this->consulente->id)
            ->whereNotIn('nome', $casos)
            ->where('preco_origem', 'like', 'ia_cenario_%')
            ->get();

        $this->assertNotEmpty($alinhados);

        foreach ($alinhados as $produto) {
            $log = \App\Models\LogsSugestaoIa::where('produto_id', $produto->id)->sole();
            $escolhido = collect($log->cenarios_retornados)->firstWhere('id', $log->cenario_escolhido);

            $this->assertSame('ia_'.$log->cenario_escolhido, $produto->preco_origem, $produto->nome);
            $this->assertEqualsWithDelta((float) $escolhido['preco_sugerido'], (float) $produto->preco_venda_atual, 0.001);
            $this->assertSame('simulado', $log->provedor_ia);
        }

        // As sondas de piso são produtos novos: sem preço e sem recomendação.
        $sondas = Produto::with(['categoria', 'variantes'])
            ->where('loja_id', $this->consulente->id)->whereNull('preco_venda_atual')->get();
        $this->assertCount(3, $sondas);
        foreach (app(RecomendacaoCenarioDados::class)->avaliar($this->consulente, $sondas) as $avaliacao) {
            $this->assertFalse($avaliacao['disponivel']);
        }

        // A demonstração não inventa histórico de aceitação.
        $this->assertDatabaseCount('logs_recomendacao_cenario', 0);
    }

    public function test_resumo_do_seeder_imprime_os_casos_sem_divergencia(): void
    {
        $this->artisan('db:seed', ['--class' => DemoSeeder::class])
            ->expectsOutputToContain('Recomendação de cenário (casos de demonstração das regras, não de frequência)')
            ->doesntExpectOutputToContain('DIVERGE')
            ->assertSuccessful();
    }

    public function test_todo_produto_demo_tem_preco_piso(): void
    {
        $this->assertSame(0, Produto::whereIn('loja_id', Loja::where('is_demo', true)->select('id'))
            ->whereNull('preco_piso')->count());
    }

    public function test_produtos_da_loja_demo_apresentacao_passam_pelo_sugerir(): void
    {
        Sanctum::actingAs($this->consulente->user);

        $produtos = Produto::where('loja_id', $this->consulente->id)->pluck('nome', 'id');
        $this->assertGreaterThan(30, $produtos->count());

        foreach ($produtos as $id => $nome) {
            $this->postJson("/api/precificacao/sugerir/{$id}")
                ->assertOk()
                ->assertJsonCount(3, 'cenarios')
                ->assertJsonStructure(['recomendacao' => ['disponivel']]);
        }

        $this->assertDatabaseCount('logs_recomendacao_cenario', $produtos->count());
    }
}
