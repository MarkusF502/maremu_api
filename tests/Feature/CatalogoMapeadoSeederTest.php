<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Loja;
use App\Models\Produto;
use App\Models\SubCategoria;
use App\Models\User;
use App\Services\Camada3MercadoService;
use Database\Seeders\CatalogoMapeadoSeeder;
use Database\Seeders\TaxonomiaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Lojas fictícias do catálogo mapeado: anonimização dos JSON, cadastro,
 * idempotência e isolamento em relação a lojas reais e a outros seeders.
 */
class CatalogoMapeadoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-13 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── anonimização ────────────────────────────────────────────────────

    /**
     * A tabela privada da pesquisa (~/maremu-pesquisa/fontes.md) fica fora do
     * repositório. Quando ela existe na máquina, nenhum nome de loja real nem
     * link listado nela pode aparecer nos JSON versionados.
     */
    public function test_json_do_repositorio_nao_tem_nome_nem_link_da_tabela_privada(): void
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        $fontes = $home ? $home.DIRECTORY_SEPARATOR.'maremu-pesquisa'.DIRECTORY_SEPARATOR.'fontes.md' : null;

        if ($fontes === null || ! is_file($fontes)) {
            $this->markTestSkipped('Tabela privada da pesquisa não está nesta máquina.');
        }

        $texto = (string) file_get_contents($fontes);
        $proibidos = [];

        // Nome real: terceira coluna das linhas "| loja-NN | rodada | nome real | ...".
        preg_match_all('/^\|\s*loja-\d+\s*\|\s*\d+\s*\|\s*([^|]+?)\s*\|/mu', $texto, $nomes);
        foreach ($nomes[1] as $nome) {
            foreach (preg_split('/\s*[()]\s*/u', $nome) as $parte) {
                if (mb_strlen(trim($parte)) >= 3) {
                    $proibidos[] = mb_strtolower(trim($parte));
                }
            }
        }

        // Links e domínios citados em qualquer parte da tabela.
        preg_match_all('#(?:https?://)?(?:www\.)?([a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com\.br|com|net|store|online))#i', $texto, $links);
        foreach ($links[1] as $dominio) {
            $proibidos[] = mb_strtolower($dominio);
            $proibidos[] = mb_strtolower(explode('.', $dominio)[0]);
        }

        $this->assertNotEmpty($nomes[1], 'A tabela privada não tem as linhas esperadas.');
        $proibidos = array_unique($proibidos);

        foreach (glob(database_path(CatalogoMapeadoSeeder::PASTA.'/*.json')) as $arquivo) {
            $conteudo = mb_strtolower((string) file_get_contents($arquivo));

            foreach ($proibidos as $termo) {
                $this->assertDoesNotMatchRegularExpression(
                    '/(?<![\p{L}\p{N}])'.preg_quote($termo, '/').'(?![\p{L}\p{N}])/u',
                    $conteudo,
                    basename($arquivo).' cita um nome ou link da tabela privada.'
                );
            }

            $this->assertStringNotContainsString('http', $conteudo, basename($arquivo).' tem link.');
        }
    }

    public function test_json_tem_so_os_campos_previstos(): void
    {
        foreach (CatalogoMapeadoSeeder::lerCatalogos() as $catalogo) {
            $this->assertContains($catalogo['posicionamento'], ['popular', 'medio', 'premium']);
            $this->assertStringContainsString('Mapead', $catalogo['nome_ficticio']);
            $this->assertArrayNotHasKey('nome_real', $catalogo);
            $this->assertArrayNotHasKey('cidade', $catalogo);

            foreach ($catalogo['pecas'] as $peca) {
                $this->assertSame(['descricao', 'categoria_pai', 'sub_categoria', 'genero', 'preco'], array_keys($peca));
                $this->assertGreaterThan(0, $peca['preco']);
            }
        }
    }

    // ─── cadastro ────────────────────────────────────────────────────────

    public function test_cadastra_uma_loja_demo_por_json_com_o_catalogo_e_o_custo_pela_margem_bruta(): void
    {
        $this->seed(CatalogoMapeadoSeeder::class);

        $catalogos = CatalogoMapeadoSeeder::lerCatalogos();
        $margem = (require database_path('data/demo/premissas.php'))['margem_bruta'];

        $this->assertSame(count($catalogos), $this->lojasDoCatalogo()->count());

        foreach ($catalogos as $catalogo) {
            $loja = $this->lojasDoCatalogo()->where('nome', $catalogo['nome_ficticio'])->sole();

            $this->assertTrue($loja->is_demo);
            $this->assertSame($catalogo['posicionamento'], $loja->posicionamento);
            $this->assertSame(CatalogoMapeadoSeeder::emailDaLoja($catalogo['loja']), $loja->user->email);

            $produtos = Produto::where('loja_id', $loja->id)->get();
            $this->assertCount(count($catalogo['pecas']), $produtos);

            foreach ($produtos as $produto) {
                $preco = (float) $produto->preco_venda_atual;
                $this->assertEqualsWithDelta(round($preco * (1 - $margem[$loja->posicionamento]), 2), (float) $produto->custo_aquisicao, 0.001);

                $estoque = (int) DB::table('variantes_produto')->where('produto_id', $produto->id)->sum('quantidade_estoque');
                $this->assertGreaterThanOrEqual(1, $estoque);
                $this->assertLessThanOrEqual(10, $estoque);
            }

            $this->assertEqualsCanonicalizing(
                array_map(fn ($p) => round((float) $p['preco'], 2), $catalogo['pecas']),
                $produtos->map(fn ($p) => round((float) $p->preco_venda_atual, 2))->all(),
            );
        }
    }

    public function test_faixa_pelo_preco_medio_usa_a_logica_do_onboarding(): void
    {
        // Preço médio × volume da faixa tem de cair na própria faixa; vale a menor.
        $this->assertSame('ate_10k', CatalogoMapeadoSeeder::faixaPeloPrecoMedio(80, 'medio'));
        $this->assertSame('ate_10k', CatalogoMapeadoSeeder::faixaPeloPrecoMedio(250, 'medio'));
        $this->assertSame('de_30k_a_80k', CatalogoMapeadoSeeder::faixaPeloPrecoMedio(260, 'medio'));
        $this->assertSame('acima_80k', CatalogoMapeadoSeeder::faixaPeloPrecoMedio(600, 'premium'));
    }

    // ─── idempotência ────────────────────────────────────────────────────

    public function test_rodar_duas_vezes_da_o_mesmo_resultado(): void
    {
        $this->seed(CatalogoMapeadoSeeder::class);
        $primeira = $this->retrato();
        $contagens = $this->contagens();

        $this->seed(CatalogoMapeadoSeeder::class);

        $this->assertSame($contagens, $this->contagens());
        $this->assertSame($primeira, $this->retrato());
    }

    // ─── isolamento ──────────────────────────────────────────────────────

    public function test_nao_toca_loja_real_nem_loja_demo_de_outro_seeder(): void
    {
        $real = $this->lojaComProduto(demo: false, email: 'dona@loja-real.test');
        $outraDemo = $this->lojaComProduto(demo: true, email: 'demo-99@maremu.demo');
        $antes = $this->linhasDasLojas([$real->id, $outraDemo->id]);

        $this->seed(CatalogoMapeadoSeeder::class);
        $this->seed(CatalogoMapeadoSeeder::class);

        $this->assertSame($antes, $this->linhasDasLojas([$real->id, $outraDemo->id]));
        $this->assertTrue(User::where('email', 'dona@loja-real.test')->exists());
        $this->assertTrue(User::where('email', 'demo-99@maremu.demo')->exists());
    }

    /**
     * Seis lojas reais `medio` vendendo camisetas: a real vê só as outras cinco
     * reais, e a fictícia não vê nenhuma delas (as fictícias `medio` que vendem
     * camisetas não chegam a cinco além dela).
     */
    public function test_loja_real_e_loja_ficticia_nunca_se_veem_na_camada_3(): void
    {
        $this->seed(CatalogoMapeadoSeeder::class);

        $camisetas = SubCategoria::whereRelation('categoriaPai', 'nome', 'Camisetas e tops')->sole();
        $reais = [];
        for ($i = 0; $i < 6; $i++) {
            $loja = Loja::factory()->create(['posicionamento' => 'medio', 'faturamento_medio_mensal' => 20_000]);
            $categoria = Categoria::factory()->create(['loja_id' => $loja->id, 'sub_categoria_id' => $camisetas->id]);
            DB::table('metricas_categoria_loja')->insert([
                'id' => (string) Str::uuid(),
                'loja_id' => $loja->id,
                'categoria_id' => $categoria->id,
                'periodo_referencia' => now()->toDateString(),
                'preco_mediano' => 9_999,
                'margem_mediana' => 0.3,
                'giro_medio_dias' => 15,
                'n_produtos' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $reais[] = [$loja, $categoria];
        }

        $mercado = app(Camada3MercadoService::class);

        // Real: amostra só de reais (5 outras), mediana igual à das reais.
        [$lojaReal, $categoriaReal] = $reais[0];
        $vistaReal = $mercado->faixaDeMercado($lojaReal, $categoriaReal);
        $this->assertTrue($vistaReal['disponivel']);
        $this->assertSame(5, $vistaReal['n_lojas']);
        $this->assertEquals(9_999, $vistaReal['preco']['mediana']);

        // Fictícia: as seis reais não entram; com as fictícias que existem, não há amostra.
        $ficticias = $this->lojasDoCatalogo()->where('posicionamento', 'medio')->get();
        $this->assertNotEmpty($ficticias);
        foreach ($ficticias as $ficticia) {
            $categoria = $ficticia->categorias()->where('sub_categoria_id', $camisetas->id)->first()
                ?? new Categoria(['loja_id' => $ficticia->id, 'sub_categoria_id' => $camisetas->id]);
            $vista = $mercado->faixaDeMercado($ficticia, $categoria);

            $outrasFicticias = $this->lojasDoCatalogo()
                ->where('posicionamento', 'medio')
                ->where('lojas.id', '!=', $ficticia->id)
                ->whereHas('categorias', fn ($q) => $q->where('sub_categoria_id', $camisetas->id))
                ->count();

            if ($outrasFicticias >= 5) {
                $this->assertSame($outrasFicticias, $vista['n_lojas']);
            } else {
                $this->assertFalse($vista['disponivel']);
                $this->assertSame('amostra_insuficiente', $vista['motivo']);
            }
        }
    }

    // ─── trava de ambiente ───────────────────────────────────────────────

    public function test_recusa_rodar_em_producao_sem_autorizacao_explicita(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/DEMO_SEED_ALLOW/');

        app(CatalogoMapeadoSeeder::class)->run();
    }

    public function test_recusa_rodar_em_producao_sem_senha_definida(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $_SERVER['DEMO_SEED_ALLOW'] = 'true';

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/DEMO_PASSWORD/');

            app(CatalogoMapeadoSeeder::class)->run();
        } finally {
            unset($_SERVER['DEMO_SEED_ALLOW']);
        }
    }

    public function test_imprime_a_camada_3_vista_de_uma_loja_medio(): void
    {
        $this->artisan('db:seed', ['--class' => CatalogoMapeadoSeeder::class])
            ->expectsOutputToContain('Camada 3 vista da')
            ->assertSuccessful();
    }

    // ─── apoio ───────────────────────────────────────────────────────────

    private function lojasDoCatalogo()
    {
        return Loja::query()
            ->where('is_demo', true)
            ->whereIn('user_id', User::where('email', 'like', '%@'.CatalogoMapeadoSeeder::DOMINIO)->select('id'));
    }

    /** @return array<string, int> */
    private function contagens(): array
    {
        $tabelas = [
            'users', 'lojas', 'canais_venda_loja', 'categorias', 'produtos',
            'variantes_produto', 'pedidos', 'itens_pedido', 'metricas_categoria_loja',
        ];

        return array_combine($tabelas, array_map(fn (string $t) => DB::table($t)->count(), $tabelas));
    }

    /** Catálogo, estoque e vendas das lojas fictícias, sem ids nem datas de gravação. */
    private function retrato(): array
    {
        return DB::table('produtos')
            ->join('lojas', 'lojas.id', '=', 'produtos.loja_id')
            ->join('variantes_produto as v', 'v.produto_id', '=', 'produtos.id')
            ->where('lojas.is_demo', true)
            ->orderBy('lojas.nome')->orderBy('produtos.sku')
            ->get(['lojas.nome as loja', 'produtos.sku', 'produtos.nome', 'produtos.preco_venda_atual', 'produtos.custo_aquisicao', 'v.quantidade_estoque'])
            ->map(fn ($linha) => (array) $linha)
            ->map(function (array $linha) {
                $linha['vendidas'] = (int) DB::table('itens_pedido')
                    ->join('produtos', 'produtos.id', '=', 'itens_pedido.produto_id')
                    ->where('produtos.sku', $linha['sku'])
                    ->sum('quantidade');

                return $linha;
            })
            ->all();
    }

    private function lojaComProduto(bool $demo, string $email): Loja
    {
        $this->seed(TaxonomiaSeeder::class);

        $user = User::factory()->create(['email' => $email]);
        $factory = $demo ? Loja::factory()->demo() : Loja::factory();
        $loja = $factory->create(['user_id' => $user->id, 'posicionamento' => 'medio', 'faturamento_medio_mensal' => 20_000]);

        $sub = SubCategoria::whereRelation('categoriaPai', 'nome', 'Calças')->sole();
        $categoria = Categoria::factory()->create(['loja_id' => $loja->id, 'sub_categoria_id' => $sub->id]);
        Produto::factory()->create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'preco_venda_atual' => 150,
            'custo_aquisicao' => 60,
            'frete_entrada_unitario' => 0,
        ]);

        return $loja;
    }

    /** Linhas de cadastro das lojas (sem métricas, que o job recalcula para todas). */
    private function linhasDasLojas(array $ids): array
    {
        $linhas = [];
        foreach (['lojas' => 'id', 'categorias' => 'loja_id', 'produtos' => 'loja_id', 'canais_venda_loja' => 'loja_id', 'pedidos' => 'loja_id'] as $tabela => $coluna) {
            $linhas[$tabela] = DB::table($tabela)->whereIn($coluna, $ids)->orderBy('id')->get()->map(fn ($l) => (array) $l)->all();
        }

        return $linhas;
    }
}
