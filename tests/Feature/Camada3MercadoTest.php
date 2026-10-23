<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\CategoriaPai;
use App\Models\Loja;
use App\Models\SubCategoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Camada3MercadoTest extends TestCase
{
    use RefreshDatabase;

    private Loja $loja;

    private Categoria $categoria;

    private SubCategoria $jeans;

    private SubCategoria $outros;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-23 10:00:00');

        $pai = CategoriaPai::create(['nome' => 'Calças', 'slug' => 'calcas']);
        $this->jeans = SubCategoria::create([
            'categoria_pai_id' => $pai->id,
            'nome' => 'Jeans',
            'slug' => 'jeans',
        ]);
        $this->outros = SubCategoria::create([
            'categoria_pai_id' => $pai->id,
            'nome' => 'Outros',
            'slug' => 'outros',
            'comparavel' => false,
        ]);

        // Consulente: posicionamento médio, faixa de_10k_a_30k.
        $this->loja = Loja::factory()->create([
            'posicionamento' => 'medio',
            'faturamento_medio_mensal' => 20_000,
        ]);
        $this->categoria = $this->categoria($this->loja);

        Sanctum::actingAs($this->loja->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─── tamanho da amostra ──────────────────────────────────────────────

    public function test_quatro_lojas_compativeis_nao_formam_amostra(): void
    {
        $this->concorrentes(4);

        $this->consultar()
            ->assertOk()
            ->assertExactJson(['disponivel' => false, 'motivo' => 'amostra_insuficiente']);
    }

    public function test_cinco_lojas_compativeis_formam_amostra(): void
    {
        $this->concorrentes(5);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('disponivel', true)
            ->assertJsonPath('n_lojas', 5)
            ->assertJsonPath('filtros_aplicados', ['posicionamento'])
            ->assertJsonPath('sub_categoria.id', $this->jeans->id)
            ->assertJsonPath('sub_categoria.nome', 'Jeans');
    }

    // ─── faixa de faturamento ────────────────────────────────────────────

    public function test_estreita_pela_faixa_quando_sobram_mais_de_dez_lojas(): void
    {
        // 12 lojas no mesmo posicionamento, 6 na faixa da consulente.
        $this->concorrentes(6, loja: ['faturamento_medio_mensal' => 25_000]);
        $this->concorrentes(6, loja: ['faturamento_medio_mensal' => 120_000]);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('n_lojas', 6)
            ->assertJsonPath('filtros_aplicados', ['posicionamento', 'faixa_faturamento']);
    }

    public function test_nao_estreita_quando_a_faixa_deixaria_menos_de_cinco(): void
    {
        // 12 lojas, só 3 na faixa da consulente: estreitar quebraria a amostra.
        $this->concorrentes(3, loja: ['faturamento_medio_mensal' => 25_000]);
        $this->concorrentes(9, loja: ['faturamento_medio_mensal' => 120_000]);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('n_lojas', 12)
            ->assertJsonPath('filtros_aplicados', ['posicionamento']);
    }

    public function test_dez_lojas_nao_acionam_o_estreitamento(): void
    {
        // O estreitamento só vale acima de 10: com 10 a amostra fica como está.
        $this->concorrentes(5, loja: ['faturamento_medio_mensal' => 25_000]);
        $this->concorrentes(5, loja: ['faturamento_medio_mensal' => 120_000]);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('n_lojas', 10)
            ->assertJsonPath('filtros_aplicados', ['posicionamento']);
    }

    // ─── janela de 90 dias ───────────────────────────────────────────────

    public function test_registro_com_mais_de_noventa_dias_e_ignorado(): void
    {
        $this->concorrentes(5, metrica: ['periodo_referencia' => now()->subDays(91)->toDateString()]);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('motivo', 'amostra_insuficiente');
    }

    public function test_registro_de_noventa_dias_ainda_entra(): void
    {
        $this->concorrentes(5, metrica: ['periodo_referencia' => now()->subDays(90)->toDateString()]);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('n_lojas', 5);
    }

    public function test_so_a_janela_mais_recente_de_cada_categoria_conta(): void
    {
        // Cada loja tem uma janela antiga absurda e uma recente: vale a recente.
        for ($i = 0; $i < 5; $i++) {
            $categoria = $this->categoria($this->lojaComparavel());
            $this->metrica($categoria, [
                'periodo_referencia' => now()->subDays(60)->toDateString(),
                'preco_mediano' => 9_999,
            ]);
            $this->metrica($categoria, [
                'periodo_referencia' => now()->subDays(3)->toDateString(),
                'preco_mediano' => 50,
            ]);
        }

        $resposta = $this->consultar()->assertOk()->assertJsonPath('n_lojas', 5);

        $this->assertNumero(50.0, $resposta, 'preco.mediana');
    }

    // ─── isolamento ──────────────────────────────────────────────────────

    public function test_a_propria_loja_nunca_entra_na_amostra(): void
    {
        // A consulente tem métrica própria; só 4 lojas de fora comparáveis.
        $this->metrica($this->categoria, ['preco_mediano' => 50]);
        $this->concorrentes(4);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('motivo', 'amostra_insuficiente');
    }

    public function test_loja_real_nao_ve_dado_de_loja_demo(): void
    {
        $this->concorrentes(5, demo: true);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('motivo', 'amostra_insuficiente');
    }

    public function test_loja_demo_nao_ve_dado_de_loja_real(): void
    {
        $demo = Loja::factory()->demo()->create([
            'posicionamento' => 'medio',
            'faturamento_medio_mensal' => 20_000,
        ]);
        $categoriaDemo = $this->categoria($demo);
        Sanctum::actingAs($demo->user);

        $this->concorrentes(5);

        $this->consultar($categoriaDemo)
            ->assertOk()
            ->assertJsonPath('motivo', 'amostra_insuficiente');

        // Com cinco lojas demo, a loja demo passa a ter comparação.
        $this->concorrentes(5, demo: true);

        $this->consultar($categoriaDemo)
            ->assertOk()
            ->assertJsonPath('n_lojas', 5);
    }

    public function test_posicionamento_diferente_nao_entra_na_amostra(): void
    {
        $this->concorrentes(5, loja: ['posicionamento' => 'premium']);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('motivo', 'amostra_insuficiente');
    }

    // ─── um ponto por loja ───────────────────────────────────────────────

    public function test_loja_com_quarenta_produtos_pesa_igual_a_loja_com_dois(): void
    {
        // Se a amostra fosse ponderada por produto, as duas lojas caras (80
        // produtos no total) dominariam as três baratas (6 produtos).
        $this->concorrentes(3, metrica: ['preco_mediano' => 10, 'n_produtos' => 2]);
        $this->concorrentes(2, metrica: ['preco_mediano' => 100, 'n_produtos' => 40]);

        $resposta = $this->consultar()->assertOk()->assertJsonPath('n_lojas', 5);

        $this->assertNumero(10.0, $resposta, 'preco.mediana');
    }

    public function test_loja_com_duas_categorias_na_sub_categoria_vira_um_ponto(): void
    {
        $this->concorrentes(4, metrica: ['preco_mediano' => 50]);

        // Uma loja, duas categorias em Jeans: a mediana delas é o ponto da loja.
        $outra = $this->lojaComparavel();
        $this->metrica($this->categoria($outra), ['preco_mediano' => 10]);
        $this->metrica($this->categoria($outra), ['preco_mediano' => 90]);

        $resposta = $this->consultar()->assertOk()->assertJsonPath('n_lojas', 5);

        $this->assertNumero(50.0, $resposta, 'preco.mediana');
    }

    public function test_preco_absurdo_de_uma_loja_quase_nao_desloca_a_mediana(): void
    {
        foreach ([40, 45, 50, 55, 60] as $preco) {
            $this->concorrentes(1, metrica: ['preco_mediano' => $preco]);
        }

        $this->assertNumero(50.0, $this->consultar()->assertOk(), 'preco.mediana');

        // Troca o maior preço por um outlier: a mediana não se move.
        DB::table('metricas_categoria_loja')
            ->where('preco_mediano', 60)
            ->update(['preco_mediano' => 9_999]);

        $resposta = $this->consultar()->assertOk();

        $this->assertNumero(50.0, $resposta, 'preco.mediana');
        $this->assertNumero(45.0, $resposta, 'preco.p25');
    }

    // ─── estatística ─────────────────────────────────────────────────────

    public function test_percentis_conferidos_a_mao(): void
    {
        // 6 lojas. Preço 10,20,30,40,50,100 / margem 0,1..0,6 / giro 2..12.
        $precos  = [10, 20, 30, 40, 50, 100];
        $margens = [0.10, 0.20, 0.30, 0.40, 0.50, 0.60];
        $giros   = [2, 4, 6, 8, 10, 12];

        foreach ($precos as $i => $preco) {
            $this->concorrentes(1, metrica: [
                'preco_mediano' => $preco,
                'margem_mediana' => $margens[$i],
                'giro_medio_dias' => $giros[$i],
            ]);
        }

        // PERCENTILE.INC com n=6: posição = p × 5.
        //   p25 → 1,25 → 20 + 0,25×(30−20) = 22,5
        //   p50 → 2,50 → 30 + 0,50×(40−30) = 35,0
        //   p75 → 3,75 → 40 + 0,75×(50−40) = 47,5
        $resposta = $this->consultar()->assertOk()->assertJsonPath('n_lojas', 6);

        $this->assertNumero(22.5, $resposta, 'preco.p25');
        $this->assertNumero(35.0, $resposta, 'preco.mediana');
        $this->assertNumero(47.5, $resposta, 'preco.p75');

        $this->assertNumero(0.225, $resposta, 'margem.p25');
        $this->assertNumero(0.35, $resposta, 'margem.mediana');
        $this->assertNumero(0.475, $resposta, 'margem.p75');

        $this->assertNumero(4.5, $resposta, 'giro.p25');
        $this->assertNumero(7.0, $resposta, 'giro.mediana');
        $this->assertNumero(9.5, $resposta, 'giro.p75');
    }

    public function test_giro_e_nulo_quando_menos_de_cinco_lojas_tem_giro(): void
    {
        $this->concorrentes(4, metrica: ['giro_medio_dias' => 15]);
        $this->concorrentes(2, metrica: ['giro_medio_dias' => null]);

        $resposta = $this->consultar()
            ->assertOk()
            ->assertJsonPath('n_lojas', 6)
            ->assertJsonPath('giro', null);

        // Preço e margem continuam: só o giro depende de giro.
        $this->assertNumero(50.0, $resposta, 'preco.mediana');
        $this->assertNumero(0.3, $resposta, 'margem.mediana');
    }

    public function test_giro_aparece_quando_cinco_lojas_tem_giro(): void
    {
        $this->concorrentes(5, metrica: ['giro_medio_dias' => 15]);
        $this->concorrentes(1, metrica: ['giro_medio_dias' => null]);

        $resposta = $this->consultar()->assertOk()->assertJsonPath('n_lojas', 6);

        $this->assertNumero(15.0, $resposta, 'giro.mediana');
    }

    public function test_registro_sem_fotografia_do_catalogo_nao_entra(): void
    {
        $this->concorrentes(3, metrica: ['preco_mediano' => null]);
        $this->concorrentes(2, metrica: ['n_produtos' => 0]);

        $this->consultar()
            ->assertOk()
            ->assertJsonPath('motivo', 'amostra_insuficiente');
    }

    // ─── motivos de indisponibilidade ────────────────────────────────────

    public function test_sub_categoria_nao_comparavel(): void
    {
        $categoria = $this->categoria($this->loja, $this->outros->id);
        $this->concorrentes(5, subCategoriaId: $this->outros->id);

        $this->consultar($categoria)
            ->assertOk()
            ->assertExactJson(['disponivel' => false, 'motivo' => 'sub_categoria_nao_comparavel']);
    }

    public function test_categoria_sem_classificacao(): void
    {
        $semClassificacao = Categoria::factory()->create([
            'loja_id' => $this->loja->id,
            'sub_categoria_id' => null,
        ]);
        $this->concorrentes(5);

        $this->consultar($semClassificacao)
            ->assertOk()
            ->assertExactJson(['disponivel' => false, 'motivo' => 'categoria_sem_classificacao']);
    }

    // ─── anonimato ───────────────────────────────────────────────────────

    public function test_resposta_nao_identifica_nenhuma_loja(): void
    {
        $concorrentes = $this->concorrentes(5);

        $conteudo = $this->consultar()->assertOk()->getContent();

        $this->assertStringNotContainsString('loja_id', $conteudo);
        $this->assertStringNotContainsString('categoria_id', $conteudo);

        foreach ($concorrentes as $concorrente) {
            $this->assertStringNotContainsString($concorrente->id, $conteudo);
            $this->assertStringNotContainsString($concorrente->nome, $conteudo);
        }
    }

    // ─── endpoint ────────────────────────────────────────────────────────

    public function test_categoria_de_outra_loja_responde_404(): void
    {
        $alheia = $this->categoria($this->lojaComparavel());

        $this->consultar($alheia)->assertNotFound();
    }

    public function test_sem_parametro_responde_422(): void
    {
        $this->getJson('/api/mercado/faixa')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['categoria_id' => 'Informe a categoria.']);
    }

    public function test_sem_token_responde_401(): void
    {
        // Sanctum::actingAs não é desfeito por withoutToken: usa um app limpo.
        $this->refreshApplication();

        $this->getJson("/api/mercado/faixa?categoria_id={$this->categoria->id}")
            ->assertUnauthorized();
    }

    // ─── is_demo não é preenchível por rota ──────────────────────────────

    public function test_is_demo_enviado_no_cadastro_da_loja_e_ignorado(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/loja/onboarding/salvar', $this->payloadDaLoja(['is_demo' => true]))
            ->assertCreated();

        $this->assertFalse((bool) $user->loja()->first()->is_demo);
    }

    public function test_is_demo_enviado_na_edicao_da_loja_e_ignorado(): void
    {
        $this->putJson('/api/loja/configuracoes', $this->payloadDaLoja(['is_demo' => true]))
            ->assertOk();

        $this->assertFalse((bool) $this->loja->fresh()->is_demo);
    }

    public function test_loja_demo_nao_deixa_de_ser_demo_pela_edicao(): void
    {
        $demo = Loja::factory()->demo()->create(['posicionamento' => 'medio']);
        Sanctum::actingAs($demo->user);

        $this->putJson('/api/loja/configuracoes', $this->payloadDaLoja(['is_demo' => false]))
            ->assertOk();

        $this->assertTrue((bool) $demo->fresh()->is_demo);
    }

    // ─── apoio ───────────────────────────────────────────────────────────

    /**
     * JSON não distingue 50 de 50,0: compara o número, não o tipo PHP em que
     * ele foi decodificado.
     */
    private function assertNumero(float $esperado, TestResponse $resposta, string $caminho): void
    {
        $this->assertEqualsWithDelta($esperado, $resposta->json($caminho), 0.00001, $caminho);
    }

    private function consultar(?Categoria $categoria = null): TestResponse
    {
        $id = ($categoria ?? $this->categoria)->id;

        return $this->getJson("/api/mercado/faixa?categoria_id={$id}");
    }

    /**
     * Cria N lojas comparáveis, cada uma com uma categoria na sub-categoria e
     * um registro de métricas.
     *
     * @return Loja[]
     */
    private function concorrentes(
        int $quantidade,
        array $metrica = [],
        array $loja = [],
        bool $demo = false,
        ?int $subCategoriaId = null,
    ): array {
        $lojas = [];

        for ($i = 0; $i < $quantidade; $i++) {
            $outra = $this->lojaComparavel($loja, $demo);
            $this->metrica($this->categoria($outra, $subCategoriaId), $metrica);
            $lojas[] = $outra;
        }

        return $lojas;
    }

    private function lojaComparavel(array $atributos = [], bool $demo = false): Loja
    {
        $factory = $demo ? Loja::factory()->demo() : Loja::factory();

        return $factory->create(array_merge([
            'posicionamento' => 'medio',
            'faturamento_medio_mensal' => 20_000,
        ], $atributos));
    }

    private function categoria(Loja $loja, ?int $subCategoriaId = null): Categoria
    {
        return Categoria::factory()->create([
            'loja_id' => $loja->id,
            'sub_categoria_id' => $subCategoriaId ?? $this->jeans->id,
        ]);
    }

    private function metrica(Categoria $categoria, array $valores = []): void
    {
        DB::table('metricas_categoria_loja')->insert(array_merge([
            'id' => (string) Str::uuid(),
            'loja_id' => $categoria->loja_id,
            'categoria_id' => $categoria->id,
            'periodo_referencia' => now()->toDateString(),
            'preco_mediano' => 50,
            'margem_mediana' => 0.3,
            'giro_medio_dias' => 15,
            'n_produtos' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ], $valores));
    }

    /**
     * Payload completo de cadastro/edição da loja, para checar que campos fora
     * de `$fillable` não passam pela rota.
     */
    private function payloadDaLoja(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Loja do Teste',
            'posicionamento' => 'medio',
            'regime_tributario' => 'simples_nacional',
            'faturamento_medio_mensal' => 20_000,
            'custo_fixo_mensal' => 4_500,
            'custo_fixo_origem' => 'estimado_pelo_sistema',
            'margem_lucro_desejada' => 0.35,
            'aliquota_efetiva' => 0.06,
            'aliquota_origem' => 'estimado_pelo_sistema',
            'volume_vendas_esperado' => 120,
            'canais' => [[
                'canal' => 'loja_fisica',
                'taxa_percentual' => 0.03,
                'taxa_origem' => 'estimado_pelo_sistema',
            ]],
        ], $extra);
    }
}
