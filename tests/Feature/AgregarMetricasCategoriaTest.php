<?php

namespace Tests\Feature;

use App\Jobs\AgregarMetricasCategoria;
use App\Models\Categoria;
use App\Models\Loja;
use App\Models\Produto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgregarMetricasCategoriaTest extends TestCase
{
    use RefreshDatabase;

    private Loja $loja;

    private Categoria $categoria;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 03:00:00');

        $this->loja = Loja::factory()->create();
        $this->categoria = Categoria::factory()->create(['loja_id' => $this->loja->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_giro_e_a_mediana_de_dias_por_unidade_e_o_volume_conta_unidades(): void
    {
        // 40 dias de cadastro, 4 unidades em 2 linhas -> 10 dias por unidade
        $a = $this->produto(['data_cadastro' => now()->subDays(39)]);
        $this->venda($a, 3, 100, 40, diasAtras: 5);
        $this->venda($a, 1, 100, 40, diasAtras: 2);

        // Cadastro antigo: dias observados limitados a 90; 3 unidades -> 30
        $b = $this->produto(['data_cadastro' => now()->subDays(200)]);
        $this->venda($b, 3, 100, 40, diasAtras: 10);

        $this->rodar();

        $m = $this->metricas()->sole();
        $this->assertEqualsWithDelta(20.0, (float) $m->giro_medio_dias, 0.001);
        $this->assertSame(7, (int) $m->qtd_vendas_periodo);
        $this->assertTrue((bool) $m->volume_minimo_atingido);
    }

    public function test_margens_sao_ponderadas_por_unidade_e_descontam_o_desconto_da_linha(): void
    {
        // Tabela a 100, custo 40. Vendeu 2 a 100 com 20 de desconto na linha,
        // e 8 a 100 sem desconto: receita 980, custo 400.
        $p = $this->produto(['preco_venda_atual' => 100]);
        $this->venda($p, 2, 100, 40, diasAtras: 3, desconto: 20);
        $this->venda($p, 8, 100, 40, diasAtras: 1);

        $this->rodar();

        $m = $this->metricas()->sole();
        $this->assertEqualsWithDelta(580 / 980, (float) $m->margem_realizada_media, 0.0001);
        $this->assertEqualsWithDelta(0.6, (float) $m->margem_planejada_media, 0.0001);
    }

    public function test_venda_fora_do_horizonte_nao_entra(): void
    {
        $p = $this->produto();
        $this->venda($p, 5, 100, 40, diasAtras: 120);

        $this->rodar();

        $m = $this->metricas()->sole();
        $this->assertSame(0, (int) $m->qtd_vendas_periodo);
        $this->assertNull($m->giro_medio_dias);
        $this->assertNull($m->margem_realizada_media);
        $this->assertFalse((bool) $m->volume_minimo_atingido);
    }

    public function test_categoria_sem_venda_tambem_recebe_registro(): void
    {
        $this->produto();

        $this->rodar();

        $m = $this->metricas()->sole();
        $this->assertFalse((bool) $m->volume_minimo_atingido);
        $this->assertSame(30, (int) $m->janela_dias); // sem giro, janela máxima
    }

    public function test_fotografia_do_catalogo_considera_so_produtos_com_estoque(): void
    {
        $this->produto(['preco_venda_atual' => 50, 'custo_aquisicao' => 20, 'frete_entrada_unitario' => 5], estoque: 4);
        $this->produto(['preco_venda_atual' => 100, 'custo_aquisicao' => 40, 'frete_entrada_unitario' => 0], estoque: 6);
        $this->produto(['preco_venda_atual' => 150, 'custo_aquisicao' => 60, 'frete_entrada_unitario' => 0], estoque: 1);
        $this->produto(['preco_venda_atual' => 9999], estoque: 0); // esgotado: fora

        $this->rodar();

        $m = $this->metricas()->sole();
        $this->assertEqualsWithDelta(100.0, (float) $m->preco_mediano, 0.001);
        $this->assertEqualsWithDelta(0.6, (float) $m->margem_mediana, 0.0001);
        $this->assertSame(3, (int) $m->n_produtos);
        $this->assertSame(11, (int) $m->estoque_total);
    }

    public function test_tamanho_da_janela_e_um_quarto_do_giro_entre_7_e_30_dias(): void
    {
        // giro 10 -> 2,5 -> mínimo 7
        $rapido = $this->produto(['data_cadastro' => now()->subDays(9)]);
        $this->venda($rapido, 1, 100, 40, diasAtras: 1);

        // giro 60 -> 15
        $outra = Categoria::factory()->create(['loja_id' => $this->loja->id]);
        $medio = $this->produto(['data_cadastro' => now()->subDays(59), 'categoria_id' => $outra->id]);
        $this->venda($medio, 1, 100, 40, diasAtras: 1);

        $this->rodar();

        $this->assertSame(7, (int) $this->metricas()->sole()->janela_dias);
        $this->assertSame(15, (int) $this->metricas($outra->id)->sole()->janela_dias);
    }

    public function test_rodar_duas_vezes_no_mesmo_dia_nao_duplica(): void
    {
        $p = $this->produto();
        $this->venda($p, 2, 100, 40, diasAtras: 0);

        $this->rodar();
        $this->rodar();

        $this->assertSame(1, $this->metricas()->count());
    }

    public function test_janela_fecha_no_prazo_e_a_seguinte_abre_sem_alterar_a_anterior(): void
    {
        // giro 10 -> janela de 7 dias: 07/10 a 13/10
        $p = $this->produto(['data_cadastro' => now()->subDays(9)], estoque: 50);
        $this->venda($p, 1, 100, 40, diasAtras: 0);

        $this->rodar();
        $this->assertFalse((bool) $this->metricas()->sole()->fechada);

        Carbon::setTestNow('2026-10-10 03:00:00');
        $this->venda($p, 2, 100, 40, diasAtras: 0);
        $this->rodar();

        $aberta = $this->metricas()->sole();
        $this->assertSame(3, (int) $aberta->unidades_vendidas_janela);
        $this->assertEqualsWithDelta(0.75, (float) $aberta->vendas_por_dia, 0.0001); // 3 un. em 4 dias

        Carbon::setTestNow('2026-10-13 03:00:00');
        $this->rodar();

        $fechada = $this->metricas()->sole();
        $this->assertTrue((bool) $fechada->fechada);
        $this->assertSame('2026-10-07', substr((string) $fechada->periodo_inicio, 0, 10));
        $this->assertSame('2026-10-13', substr((string) $fechada->periodo_referencia, 0, 10));

        // Mesmo dia do fechamento: nada novo
        $this->rodar();
        $this->assertSame(1, $this->metricas()->count());

        Carbon::setTestNow('2026-10-14 03:00:00');
        $this->venda($p, 4, 100, 40, diasAtras: 0);
        $this->rodar();

        $linhas = $this->metricas()->orderBy('periodo_inicio')->get();
        $this->assertCount(2, $linhas);
        $this->assertSame(3, (int) $linhas[0]->unidades_vendidas_janela); // histórico intacto
        $this->assertTrue((bool) $linhas[0]->fechada);
        $this->assertSame('2026-10-14', substr((string) $linhas[1]->periodo_inicio, 0, 10));
        $this->assertSame(4, (int) $linhas[1]->unidades_vendidas_janela);
        $this->assertFalse((bool) $linhas[1]->fechada);
    }

    public function test_registro_do_formato_antigo_e_preservado_ou_reaproveitado(): void
    {
        $p = $this->produto();
        $this->venda($p, 1, 100, 40, diasAtras: 0);

        // Antigo, de outro dia: fica como está e uma janela nova é criada.
        $this->legado('2026-09-01');
        $this->rodar();
        $this->assertSame(2, $this->metricas()->count());
        $this->assertSame(1, $this->metricas()->whereNull('periodo_inicio')->count());

        // Antigo, gravado hoje, em outra categoria: é reaproveitado (chave única).
        $outra = Categoria::factory()->create(['loja_id' => $this->loja->id]);
        $this->produto(['categoria_id' => $outra->id]);
        $this->legado('2026-10-07', $outra->id);
        $this->rodar();

        $linha = $this->metricas($outra->id)->sole();
        $this->assertNotNull($linha->periodo_inicio);
    }

    public function test_lojas_nao_se_misturam(): void
    {
        $p = $this->produto();
        $this->venda($p, 2, 100, 40, diasAtras: 1);

        $outraLoja = Loja::factory()->create();
        $catOutra = Categoria::factory()->create(['loja_id' => $outraLoja->id]);
        $q = $this->produto(['loja_id' => $outraLoja->id, 'categoria_id' => $catOutra->id]);
        $this->venda($q, 9, 100, 40, diasAtras: 1, loja: $outraLoja);

        $this->rodar();

        $this->assertSame(2, (int) $this->metricas()->sole()->qtd_vendas_periodo);
        $this->assertSame(9, (int) DB::table('metricas_categoria_loja')
            ->where('loja_id', $outraLoja->id)->sole()->qtd_vendas_periodo);
    }

    // ─── apoio ───────────────────────────────────────────────────────────

    private function rodar(): void
    {
        (new AgregarMetricasCategoria)->handle();
    }

    private function metricas(?string $categoriaId = null)
    {
        return DB::table('metricas_categoria_loja')
            ->where('loja_id', $this->loja->id)
            ->where('categoria_id', $categoriaId ?? $this->categoria->id);
    }

    private function produto(array $atributos = [], int $estoque = 10): Produto
    {
        $produto = Produto::factory()->create(array_merge([
            'loja_id' => $this->loja->id,
            'categoria_id' => $this->categoria->id,
            'custo_aquisicao' => 40,
            'frete_entrada_unitario' => 0,
            'preco_venda_atual' => 100,
            'status' => 'ativo',
            'data_cadastro' => now()->subDays(29),
        ], $atributos));

        DB::table('variantes_produto')->insert([
            'id' => (string) Str::uuid(),
            'produto_id' => $produto->id,
            'tamanho' => 'M',
            'quantidade_estoque' => $estoque,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $produto;
    }

    private function venda(Produto $produto, int $quantidade, float $preco, float $custo, int $diasAtras, float $desconto = 0, ?Loja $loja = null): void
    {
        $pedidoId = (string) Str::uuid();
        $quando = now()->subDays($diasAtras);

        DB::table('pedidos')->insert([
            'id' => $pedidoId,
            'loja_id' => ($loja ?? $this->loja)->id,
            'canal_venda' => 'loja_fisica',
            'valor_total' => $preco * $quantidade - $desconto,
            'data_venda' => $quando,
            'created_at' => $quando,
            'updated_at' => $quando,
        ]);

        DB::table('itens_pedido')->insert([
            'id' => (string) Str::uuid(),
            'pedido_id' => $pedidoId,
            'produto_id' => $produto->id,
            'quantidade' => $quantidade,
            'preco_unitario_vendido' => $preco,
            'custo_unitario_no_momento' => $custo,
            'desconto_aplicado' => $desconto,
            'created_at' => $quando,
            'updated_at' => $quando,
        ]);
    }

    private function legado(string $data, ?string $categoriaId = null): void
    {
        DB::table('metricas_categoria_loja')->insert([
            'id' => (string) Str::uuid(),
            'loja_id' => $this->loja->id,
            'categoria_id' => $categoriaId ?? $this->categoria->id,
            'periodo_referencia' => $data,
            'qtd_vendas_periodo' => 3,
            'volume_minimo_atingido' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
