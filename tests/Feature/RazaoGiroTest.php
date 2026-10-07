<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Loja;
use App\Models\MetricasCategoriaLoja;
use App\Models\Produto;
use App\Models\VarianteProduto;
use App\Services\PrecificacaoPayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RazaoGiroTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uma loja que vende exatamente o que planejou tem razão 1, seja qual
     * for o tamanho do catálogo.
     */
    #[DataProvider('catalogos')]
    public function test_loja_no_plano_tem_razao_um_com_qualquer_catalogo(int $produtos): void
    {
        [$loja, $categoria, $produto] = $this->loja(volume: 120, produtos: $produtos);

        // 120 un./mês em N produtos -> cada um vende 1 unidade a cada 30N/120 dias
        $this->metrica($loja, $categoria, giro: 30 * $produtos / 120);

        $camada4 = app(PrecificacaoPayloadService::class)->montar($produto)['camada_4'];

        $this->assertEqualsWithDelta(1.0, $camada4['razao_giro'], 0.02);
    }

    public static function catalogos(): array
    {
        return [[15], [30], [60]];
    }

    public function test_categoria_tres_vezes_mais_lenta_tem_razao_tres(): void
    {
        [$loja, $categoria, $produto] = $this->loja(volume: 120, produtos: 40);
        $this->metrica($loja, $categoria, giro: 30.0); // esperado: 10 dias

        $camada4 = app(PrecificacaoPayloadService::class)->montar($produto)['camada_4'];

        $this->assertEqualsWithDelta(3.0, $camada4['razao_giro'], 0.001);
    }

    public function test_produto_inativo_ou_sem_estoque_nao_conta_no_catalogo(): void
    {
        [$loja, $categoria] = $this->loja(volume: 120, produtos: 4);

        $inativo = Produto::factory()->create(['loja_id' => $loja->id, 'categoria_id' => $categoria->id, 'status' => 'inativo']);
        VarianteProduto::create(['produto_id' => $inativo->id, 'tamanho' => 'M', 'quantidade_estoque' => 5]);

        $esgotado = Produto::factory()->create(['loja_id' => $loja->id, 'categoria_id' => $categoria->id, 'status' => 'ativo']);
        VarianteProduto::create(['produto_id' => $esgotado->id, 'tamanho' => 'M', 'quantidade_estoque' => 0]);

        $this->assertEqualsWithDelta(1.0, app(PrecificacaoPayloadService::class)->giroEsperadoDias($loja), 0.0001);
    }

    public function test_sem_produto_com_estoque_a_razao_fica_ausente(): void
    {
        [$loja, $categoria] = $this->loja(volume: 120, produtos: 0);
        $produto = Produto::factory()->create(['loja_id' => $loja->id, 'categoria_id' => $categoria->id, 'status' => 'ativo']);
        $this->metrica($loja, $categoria, giro: 10.0);

        $camada4 = app(PrecificacaoPayloadService::class)->montar($produto)['camada_4'];

        $this->assertArrayNotHasKey('razao_giro', $camada4);
    }

    private function loja(int $volume, int $produtos): array
    {
        $loja = Loja::factory()->create(['volume_vendas_esperado' => $volume]);
        $categoria = Categoria::factory()->create(['loja_id' => $loja->id]);

        $primeiro = null;
        for ($i = 0; $i < $produtos; $i++) {
            $produto = Produto::factory()->create([
                'loja_id' => $loja->id,
                'categoria_id' => $categoria->id,
                'status' => 'ativo',
            ]);
            VarianteProduto::create(['produto_id' => $produto->id, 'tamanho' => 'M', 'quantidade_estoque' => 3]);
            $primeiro ??= $produto;
        }

        return [$loja, $categoria, $primeiro];
    }

    private function metrica(Loja $loja, Categoria $categoria, float $giro): void
    {
        MetricasCategoriaLoja::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'periodo_referencia' => now()->toDateString(),
            'volume_minimo_atingido' => true,
            'giro_medio_dias' => $giro,
            'qtd_vendas_periodo' => 10,
            'data_calculo' => now(),
        ]);
    }
}
