<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Loja;
use App\Models\Produto;
use App\Models\User;
use App\Services\Camada3MercadoService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Confere que o cenário de demonstração produz, do ponto de vista da Loja Demo
 * Apresentação, exatamente os casos documentados no DemoSeeder — é o que a
 * avaliação do TCC vai mostrar na tela.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private Loja $consulente;

    private Camada3MercadoService $mercado;

    /** @var array<string, mixed> */
    private array $premissas;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-23 10:00:00');

        $this->premissas = require database_path('data/demo/premissas.php');
        $this->mercado = app(Camada3MercadoService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── os casos da distribuição ────────────────────────────────────────

    public function test_camisetas_estreita_a_amostra_pela_faixa_de_faturamento(): void
    {
        $this->semear();

        $faixa = $this->consultar('Camisetas e tops');

        $this->assertTrue($faixa['disponivel']);
        $this->assertSame(6, $faixa['n_lojas']);
        $this->assertSame(['posicionamento', 'faixa_faturamento'], $faixa['filtros_aplicados']);
    }

    public function test_calcas_fica_no_posicionamento_porque_a_faixa_quebraria_a_amostra(): void
    {
        $this->semear();

        $faixa = $this->consultar('Calças');

        $this->assertTrue($faixa['disponivel']);
        $this->assertSame(12, $faixa['n_lojas']);
        $this->assertSame(['posicionamento'], $faixa['filtros_aplicados']);
    }

    public function test_vestidos_tem_exatamente_cinco_lojas(): void
    {
        $this->semear();

        $faixa = $this->consultar('Vestidos');

        $this->assertTrue($faixa['disponivel']);
        $this->assertSame(5, $faixa['n_lojas']);
        $this->assertSame(['posicionamento'], $faixa['filtros_aplicados']);
    }

    public function test_casacos_nao_forma_amostra(): void
    {
        $this->semear();

        $this->assertSame(
            ['disponivel' => false, 'motivo' => 'amostra_insuficiente'],
            $this->consultar('Casacos e jaquetas'),
        );
    }

    public function test_sutias_esta_disponivel_e_inclui_a_loja_especializada(): void
    {
        $this->semear();

        $faixa = $this->consultar('Sutiãs');

        $this->assertTrue($faixa['disponivel']);
        $this->assertSame(5, $faixa['n_lojas']);

        // A especializada tem mais da metade do catálogo em lingerie ou
        // sub-categorias complementares, e continua na amostra de sutiãs.
        $especialista = Loja::whereRelation('user', 'email', DemoSeeder::EMAIL_ESPECIALISTA)->sole();
        $total = $especialista->produtos()->count();
        $deRoupaIntima = $especialista->produtos()
            ->whereRelation('categoria.subCategoria', function ($query) {
                $query->where('complementar', true)->orWhere('nome', 'Lingerie');
            })
            ->count();

        $this->assertGreaterThan($total / 2, $deRoupaIntima);
        $this->assertTrue($especialista->categorias()->whereRelation('subCategoria', 'nome', 'Sutiãs')->exists());
    }

    public function test_meias_esta_disponivel(): void
    {
        $this->semear();

        $faixa = $this->consultar('Meias');

        $this->assertTrue($faixa['disponivel']);
        $this->assertSame(5, $faixa['n_lojas']);
    }

    public function test_categoria_em_outros_nao_e_comparavel(): void
    {
        $this->semear();

        $this->assertSame(
            ['disponivel' => false, 'motivo' => 'sub_categoria_nao_comparavel'],
            $this->consultar('Acessórios de inverno'),
        );
    }

    public function test_categoria_sem_classificacao(): void
    {
        $this->semear();

        $this->assertSame(
            ['disponivel' => false, 'motivo' => 'categoria_sem_classificacao'],
            $this->consultar('Bazar'),
        );
    }

    public function test_trocar_o_posicionamento_da_consulente_tambem_da_faixas(): void
    {
        $this->semear();

        foreach (['popular', 'premium'] as $posicionamento) {
            $this->consulente->update(['posicionamento' => $posicionamento]);
            $this->consulente->refresh();

            foreach (['Camisetas e tops', 'Calças', 'Vestidos', 'Casacos e jaquetas', 'Sutiãs', 'Meias'] as $categoria) {
                $faixa = $this->consultar($categoria);

                $this->assertTrue(
                    $faixa['disponivel'],
                    "{$categoria} deveria ter faixa disponível em {$posicionamento}.",
                );
                $this->assertSame(6, $faixa['n_lojas'], "{$categoria} em {$posicionamento}");
            }
        }
    }

    // ─── premissas e estatística ─────────────────────────────────────────

    public function test_mediana_de_preco_fica_a_menos_de_vinte_e_cinco_por_cento_do_preco_base(): void
    {
        $this->semear();

        // O preço base efetivo é o de rede ajustado para loja pequena; o fator
        // sorteado por loja espalha as medianas em torno dele.
        $tolerancia = 0.25;

        foreach ($this->premissas['precos_base'] as $linha) {
            $faixa = $this->faixaDaSubCategoria($linha['pai'], $linha['sub']);

            if (! $faixa['disponivel']) {
                continue; // Casacos é insuficiente de propósito
            }

            $base = $linha['medio'] * $this->premissas['fator_loja_pequena'];

            $this->assertEqualsWithDelta(
                $base,
                $faixa['preco']['mediana'],
                $base * $tolerancia,
                "{$linha['pai']} › {$linha['sub']}: mediana fora de ±".($tolerancia * 100).'% do preço base.',
            );
        }
    }

    public function test_largura_da_faixa_fica_entre_dez_e_cinquenta_por_cento(): void
    {
        $this->semear();

        $casos = 0;

        foreach ($this->premissas['precos_base'] as $linha) {
            $faixa = $this->faixaDaSubCategoria($linha['pai'], $linha['sub']);

            if (! $faixa['disponivel']) {
                continue;
            }

            $casos++;
            $largura = $faixa['preco']['p75'] / $faixa['preco']['p25'] - 1;
            $rotulo = "{$linha['pai']} › {$linha['sub']}: largura de ".round($largura * 100, 1).'%';

            // Faixa estreita demais reprovaria qualquer preço; larga demais
            // não diria nada. O fator de preço por loja é o que a sustenta.
            $this->assertGreaterThanOrEqual(0.10, $largura, $rotulo);
            $this->assertLessThanOrEqual(0.50, $largura, $rotulo);
        }

        $this->assertSame(5, $casos, 'Só Casacos deveria ficar sem faixa.');
    }

    public function test_sondas_de_piso_caem_nas_zonas_pedidas(): void
    {
        $this->semear();

        $alcancadas = [];

        foreach ($this->premissas['sondas_de_piso'] as $sonda) {
            [$pai, $sub] = explode('|', $sonda['sub']);
            $faixa = $this->faixaDaSubCategoria($pai, $sub);
            $categoria = $this->categoriaDaSubCategoria($pai, $sub);

            $sondaCriada = Produto::where('loja_id', $this->consulente->id)
                ->where('categoria_id', $categoria->id)
                ->whereNull('preco_venda_atual')
                ->first();

            // Meias é impossível de propósito: o custo fixo por peça da loja
            // já supera o piso alvo, então o seeder não cria o produto.
            if ($sub === 'Meias') {
                $this->assertNull($sondaCriada, 'A sonda de meias não deveria existir.');

                continue;
            }

            $this->assertNotNull($sondaCriada, "Sonda ausente em {$pai} › {$sub}.");

            $piso = (float) $sondaCriada->preco_piso;
            $alcancadas[] = match (true) {
                $piso > $faixa['preco']['p75'] => 'acima_do_p75',
                $piso < $faixa['preco']['p25'] => 'abaixo_do_p25',
                default => 'entre_p25_e_p75',
            };

            $this->assertSame($sonda['zona'], end($alcancadas), "{$pai} › {$sub}: piso R$ {$piso}");
        }

        $this->assertSame(['acima_do_p75', 'entre_p25_e_p75', 'abaixo_do_p25'], $alcancadas);
    }

    public function test_piso_da_sonda_e_o_que_o_pricing_engine_calcula(): void
    {
        $this->semear();

        $taxaCanal = (float) $this->consulente->canaisAtivos()->max('taxa_percentual');
        $engine = app(\App\Services\PricingEngine::class);

        $sondas = Produto::where('loja_id', $this->consulente->id)->whereNull('preco_venda_atual')->get();

        $this->assertCount(3, $sondas);

        foreach ($sondas as $sonda) {
            $composicao = $engine->composicao(
                custoAquisicao: (float) $sonda->custo_aquisicao,
                freteEntradaUnitario: (float) $sonda->frete_entrada_unitario,
                aliquotaEfetiva: (float) $this->consulente->aliquota_efetiva,
                taxaCanal: $taxaCanal,
                custoFixoMensal: (float) $this->consulente->custo_fixo_mensal,
                volumeVendasEsperado: (int) $this->consulente->volume_vendas_esperado,
            );

            $this->assertEqualsWithDelta((float) $sonda->preco_piso, $composicao['preco_piso'], 0.01, $sonda->nome);
            // O custo fixo por peça da consulente é o documentado na premissa.
            $this->assertEqualsWithDelta(15.00, $composicao['custo_fixo_unitario'], 0.01);
        }
    }

    public function test_custo_fixo_por_peca_da_consulente_e_o_documentado(): void
    {
        $this->semear();

        $premissa = $this->premissas['consulente'];

        $this->assertEqualsWithDelta($premissa['custo_fixo_mensal'], (float) $this->consulente->custo_fixo_mensal, 0.01);
        $this->assertSame($premissa['volume_vendas_esperado'], (int) $this->consulente->volume_vendas_esperado);
        $this->assertEqualsWithDelta(
            15.00,
            (float) $this->consulente->custo_fixo_mensal / (int) $this->consulente->volume_vendas_esperado,
            0.01,
        );
    }

    public function test_margem_mediana_segue_a_premissa_do_posicionamento(): void
    {
        $this->semear();

        $esperada = $this->premissas['margem_bruta']['medio'];
        $tolerancia = $this->premissas['variacao_margem'];

        $faixa = $this->consultar('Camisetas e tops');

        $this->assertEqualsWithDelta($esperada, $faixa['margem']['mediana'], $tolerancia);
    }

    public function test_consulente_tem_categoria_de_giro_rapido_e_produto_encalhado(): void
    {
        $this->semear();

        $giros = DB::table('metricas_categoria_loja as m')
            ->join('categorias as c', 'c.id', '=', 'm.categoria_id')
            ->where('m.loja_id', $this->consulente->id)
            ->pluck('m.giro_medio_dias', 'c.nome');

        // Camisetas gira muito mais rápido que casacos, que carrega o encalhado.
        $this->assertLessThan(10, (float) $giros['Camisetas e tops']);
        $this->assertGreaterThan((float) $giros['Camisetas e tops'], (float) $giros['Casacos e jaquetas']);

        $encalhado = Produto::where('loja_id', $this->consulente->id)
            ->where('nome', 'Casaco de inverno encalhado')
            ->sole();

        $this->assertGreaterThan(0, $encalhado->variantes()->sum('quantidade_estoque'));

        $ultimaVenda = DB::table('itens_pedido as item')
            ->join('pedidos as pedido', 'pedido.id', '=', 'item.pedido_id')
            ->where('item.produto_id', $encalhado->id)
            ->max('pedido.data_venda');

        $this->assertGreaterThan(60, Carbon::parse($ultimaVenda)->diffInDays(now()));
    }

    // ─── idempotência ────────────────────────────────────────────────────

    public function test_rodar_duas_vezes_mantem_as_mesmas_contagens(): void
    {
        $this->semear();
        $antes = $this->contagens();
        $faixaAntes = $this->consultar('Camisetas e tops');

        $this->semear();

        $this->assertSame($antes, $this->contagens());
        $this->assertSame($faixaAntes, $this->consultar('Camisetas e tops'));
    }

    // ─── loja real intocada ──────────────────────────────────────────────

    public function test_loja_real_anterior_ao_seeder_continua_intacta(): void
    {
        $real = $this->criarLojaReal();
        $antes = [
            'loja' => Loja::where('id', $real->id)->sole(),
            'categorias' => $real->categorias()->count(),
            'produtos' => $real->produtos()->count(),
            'pedidos' => DB::table('pedidos')->where('loja_id', $real->id)->count(),
            'usuario' => $real->user->only(['id', 'name', 'email', 'password']),
        ];

        $this->semear();

        $real->refresh();
        $this->assertFalse($real->is_demo);
        $this->assertSame($antes['loja']->nome, $real->nome);
        $this->assertSame($antes['loja']->posicionamento, $real->posicionamento);
        $this->assertSame($antes['categorias'], $real->categorias()->count());
        $this->assertSame($antes['produtos'], $real->produtos()->count());
        $this->assertSame($antes['pedidos'], DB::table('pedidos')->where('loja_id', $real->id)->count());
        $this->assertSame($antes['usuario'], $real->user->fresh()->only(['id', 'name', 'email', 'password']));
    }

    public function test_consulta_de_loja_real_nunca_usa_loja_demo(): void
    {
        $real = $this->criarLojaReal();
        $this->semear();

        // Mesmo posicionamento e faixa das 12 lojas demo de camisetas, e uma
        // categoria na mesma sub-categoria: ainda assim, sem amostra.
        $categoria = $real->categorias()->whereRelation('subCategoria', 'nome', 'Geral')->first();

        $this->assertSame(
            ['disponivel' => false, 'motivo' => 'amostra_insuficiente'],
            $this->mercado->faixaDeMercado($real->fresh(), $categoria),
        );
    }

    public function test_o_seeder_nao_apaga_loja_real(): void
    {
        $real = $this->criarLojaReal();

        $this->semear();
        $this->semear();

        $this->assertTrue(Loja::where('id', $real->id)->exists());
        $this->assertTrue(User::where('id', $real->user_id)->exists());
        $this->assertSame(1, Loja::where('is_demo', false)->count());
    }

    // ─── trava de ambiente ───────────────────────────────────────────────

    public function test_recusa_rodar_em_producao_sem_autorizacao_explicita(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEMO_SEED_ALLOW/');

        app(DemoSeeder::class)->run();
    }

    public function test_recusa_rodar_em_producao_sem_senha_definida(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $_SERVER['DEMO_SEED_ALLOW'] = 'true';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/DEMO_PASSWORD/');

            app(DemoSeeder::class)->run();
        } finally {
            unset($_SERVER['DEMO_SEED_ALLOW']);
        }
    }

    public function test_roda_em_producao_quando_autorizado_com_senha(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $_SERVER['DEMO_SEED_ALLOW'] = 'true';
        $_SERVER['DEMO_PASSWORD'] = 'senha-da-apresentacao';

        try {
            app(DemoSeeder::class)->run();

            $consulente = Loja::where('nome', DemoSeeder::NOME_CONSULENTE)->sole();
            $this->assertTrue($consulente->is_demo);
            $this->assertTrue(Hash::check('senha-da-apresentacao', $consulente->user->password));
        } finally {
            unset($_SERVER['DEMO_SEED_ALLOW'], $_SERVER['DEMO_PASSWORD']);
        }
    }

    // ─── apoio ───────────────────────────────────────────────────────────

    private function semear(): void
    {
        $this->seed(DemoSeeder::class);

        $this->consulente = Loja::where('nome', DemoSeeder::NOME_CONSULENTE)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function consultar(string $nomeDaCategoria): array
    {
        $categoria = $this->consulente->categorias()->where('nome', $nomeDaCategoria)->sole();

        return $this->mercado->faixaDeMercado($this->consulente, $categoria);
    }

    /**
     * @return array<string, mixed>
     */
    private function faixaDaSubCategoria(string $pai, string $sub): array
    {
        return $this->mercado->faixaDeMercado($this->consulente, $this->categoriaDaSubCategoria($pai, $sub));
    }

    private function categoriaDaSubCategoria(string $pai, string $sub): Categoria
    {
        return $this->consulente->categorias()
            ->whereRelation('subCategoria', fn ($query) => $query
                ->where('nome', $sub)
                ->whereRelation('categoriaPai', 'nome', $pai))
            ->sole();
    }

    /**
     * @return array<string, int>
     */
    private function contagens(): array
    {
        $tabelas = [
            'users', 'lojas', 'canais_venda_loja', 'categorias', 'produtos',
            'variantes_produto', 'pedidos', 'itens_pedido', 'metricas_categoria_loja',
        ];

        return array_combine($tabelas, array_map(fn (string $t) => DB::table($t)->count(), $tabelas));
    }

    /**
     * Loja real (is_demo = false) no mesmo posicionamento e faixa da consulente,
     * com uma categoria na mesma sub-categoria das lojas demo de camisetas.
     */
    private function criarLojaReal(): Loja
    {
        $this->seed(\Database\Seeders\TaxonomiaSeeder::class);

        $loja = Loja::factory()->create([
            'nome' => 'Loja de Verdade',
            'posicionamento' => 'medio',
            'faturamento_medio_mensal' => 20_000,
        ]);

        $sub = \App\Models\SubCategoria::whereRelation('categoriaPai', 'nome', 'Camisetas e tops')->sole();
        $categoria = Categoria::factory()->create([
            'loja_id' => $loja->id,
            'nome' => 'Camisetas da casa',
            'sub_categoria_id' => $sub->id,
        ]);

        Produto::factory()->create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'preco_venda_atual' => 90,
            'custo_aquisicao' => 40,
            'frete_entrada_unitario' => 0,
        ]);

        return $loja;
    }
}
