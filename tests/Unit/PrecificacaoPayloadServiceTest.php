<?php

namespace Tests\Unit;

use App\Models\CanalVenda;
use App\Models\CanalVendaLoja;
use App\Models\Categoria;
use App\Models\Loja;
use App\Models\User;
use App\Models\MetricasCategoriaLoja;
use App\Models\Produto;
use App\Services\PrecificacaoPayloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Testes unitários para PrecificacaoPayloadService.
 */
class PrecificacaoPayloadServiceTest extends TestCase
{
    use RefreshDatabase;

    private PrecificacaoPayloadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PrecificacaoPayloadService();
    }
private function criarLojaECategoriaBase(): array
    {
        $user = User::create([
            'name' => 'Usuario Service',
            'email' => 'service_' . Str::random(5) . '@maremu.com',
            'password' => bcrypt('password123'),
        ]);

        $loja = Loja::create([
            'user_id' => $user->id,
            'nome' => 'Loja Service',
            'posicionamento' => 'premium',
            'regime_tributario' => 'simples_nacional',
            'faturamento_medio_mensal' => 15000,
            'custo_fixo_mensal' => 4000,
            'margem_lucro_desejada' => 0.40,
            'aliquota_efetiva' => 0.065,
            'volume_vendas_esperado' => 300,
        ]);

        $categoria = Categoria::create(['nome' => 'Blusas']);

        return [$loja, $categoria];
    }

    public function test_camada_1_contem_preco_piso_e_custo_base_correto(): void
    {
        [$loja, $categoria] = $this->criarLojaECategoriaBase();

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Blusa Básica',
            'status' => 'ativo',
            'preco_piso' => 55.00,
            'custo_aquisicao' => 30.00,
            'frete_entrada_unitario' => 5.00,
        ]);

        $payload = $this->service->montar($produto);

        $this->assertSame(55.0, $payload['camada_1']['preco_piso']);
        $this->assertSame(35.0, $payload['camada_1']['custo_base']);
        $this->assertSame(30.0, $payload['camada_1']['custo_origem']['aquisicao']);
        $this->assertSame(5.0, $payload['camada_1']['custo_origem']['frete']);
    }

    public function test_camada_2_inclui_dados_da_loja_canais_e_produto(): void
    {
        [$loja, $categoria] = $this->criarLojaECategoriaBase();

        CanalVendaLoja::create([
            'loja_id' => $loja->id,
            'canal' => 'instagram_whatsapp',
            'taxa_percentual' => 0.035,
            'ativo' => true,
        ]);

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Blusa Floral',
            'status' => 'ativo',
            'custo_aquisicao' => 10.00,
            'frete_entrada_unitario' => 0,
            'genero' => null,
            'sku' => null,
        ]);

        $payload = $this->service->montar($produto);

        $this->assertSame('premium', $payload['camada_2']['loja']['posicionamento']);
        $this->assertSame(300, $payload['camada_2']['loja']['volume_vendas_esperado']);
        $this->assertCount(1, $payload['camada_2']['canais']);
        $this->assertSame('instagram_whatsapp', $payload['camada_2']['canais'][0]['canal']);

        $this->assertSame('Blusa Floral', $payload['camada_2']['produto']['nome']);
        $this->assertSame('Blusas', $payload['camada_2']['produto']['categoria']);

        $this->assertArrayNotHasKey('genero', $payload['camada_2']['produto']);
        $this->assertArrayNotHasKey('sku', $payload['camada_2']['produto']);
    }

    public function test_camada_2_inclui_campos_opcionais_quando_preenchidos(): void
    {
        [$loja, $categoria] = $this->criarLojaECategoriaBase();

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Blusa Inverno',
            'status' => 'ativo',
            'custo_aquisicao' => 40.00,
            'frete_entrada_unitario' => 0,
            'genero' => 'feminino',
            'sku' => 'BLZ-001',
        ]);

        $payload = $this->service->montar($produto);

        $this->assertSame('feminino', $payload['camada_2']['produto']['genero']);
        $this->assertSame('BLZ-001', $payload['camada_2']['produto']['sku']);
    }

    public function test_camada_4_e_nula_quando_nao_ha_metrica_com_volume_minimo_atingido(): void
    {
        [$loja, $categoria] = $this->criarLojaECategoriaBase();

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Produto Métrica',
            'status' => 'ativo',
            'custo_aquisicao' => 15.00,
            'frete_entrada_unitario' => 0,
        ]);

        MetricasCategoriaLoja::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'periodo_referencia' => '2026-05',
            'data_calculo' => now(),
            'volume_minimo_atingido' => false,
            'qtd_vendas_periodo' => 5,
        ]);

        $payload = $this->service->montar($produto);

        $this->assertNull($payload['camada_4']);
    }

    public function test_camada_4_traz_a_metrica_mais_recente_quando_volume_minimo_atingido(): void
    {
        [$loja, $categoria] = $this->criarLojaECategoriaBase();

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Produto Métrica Atingida',
            'status' => 'ativo',
            'custo_aquisicao' => 15.00,
            'frete_entrada_unitario' => 0,
        ]);

        MetricasCategoriaLoja::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'volume_minimo_atingido' => true,
            'periodo_referencia' => '2026-05',
            'giro_medio_dias' => 20,
            'margem_realizada_media' => 35.0,
            'margem_planejada_media' => 40.0,
            'qtd_vendas_periodo' => 50,
            'data_calculo' => now()->subMonth(),
            'candidatos_liquidacao' => null,
        ]);

        MetricasCategoriaLoja::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'volume_minimo_atingido' => true,
            'periodo_referencia' => '2026-06',
            'giro_medio_dias' => 15,
            'margem_realizada_media' => 38.0,
            'margem_planejada_media' => 40.0,
            'qtd_vendas_periodo' => 60,
            'data_calculo' => now(),
            'candidatos_liquidacao' => ['produto_x'],
        ]);

        $payload = $this->service->montar($produto);

        $this->assertNotNull($payload['camada_4']);
        
        $periodo = is_object($payload['camada_4']['periodo_referencia']) 
            ? $payload['camada_4']['periodo_referencia']->format('Y-m') 
            : $payload['camada_4']['periodo_referencia'];
            
        $this->assertSame('2026-06', $periodo);
        $this->assertSame(15.0, $payload['camada_4']['giro_medio_dias']);
        $this->assertSame(['produto_x'], $payload['camada_4']['candidatos_liquidacao']);

        $this->assertArrayNotHasKey('campo_inexistente', $payload['camada_4']);
    }

    public function test_camada_4_remove_candidatos_liquidacao_quando_vazio(): void
    {
        [$loja, $categoria] = $this->criarLojaECategoriaBase();

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Produto Vazio Liq',
            'status' => 'ativo',
            'custo_aquisicao' => 15.00,
            'frete_entrada_unitario' => 0,
        ]);

        MetricasCategoriaLoja::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'periodo_referencia' => '2026-06',
            'data_calculo' => now(),
            'qtd_vendas_periodo' => 50,
            'volume_minimo_atingido' => true,
            'candidatos_liquidacao' => null,
        ]);

        $payload = $this->service->montar($produto);

        $this->assertArrayNotHasKey('candidatos_liquidacao', $payload['camada_4']);
    }

    public function test_meta_contem_ids_e_timestamp_de_geracao(): void
    {
        [$loja, $categoria] = $this->criarLojaECategoriaBase();

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Produto Meta',
            'status' => 'ativo',
            'custo_aquisicao' => 10.00,
            'frete_entrada_unitario' => 0,
        ]);

        $payload = $this->service->montar($produto);

        $this->assertSame($produto->id, $payload['meta']['produto_id']);
        $this->assertSame($loja->id, $payload['meta']['loja_id']);
        $this->assertSame($categoria->id, $payload['meta']['categoria_id']);
        $this->assertNotEmpty($payload['meta']['gerado_em']);
    }
}