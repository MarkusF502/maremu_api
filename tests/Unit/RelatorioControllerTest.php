<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Loja;
use App\Models\Produto;
use App\Models\User;
use App\Models\VarianteProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Testes para RelatorioController@index.
 */
class RelatorioControllerTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarComLoja(): array
    {
        $user = User::create([
            'name' => 'Usuario Teste',
            'email' => 'teste_relatorio_' . Str::random(5) . '@maremu.com',
            'password' => bcrypt('password123'),
        ]);

        $loja = Loja::create([
            'user_id' => $user->id,
            'nome' => 'Loja Relatórios',
            'posicionamento' => 'medio',
            'regime_tributario' => 'simples_nacional',
            'faturamento_medio_mensal' => 20000,
            'custo_fixo_mensal' => 4500,
            'margem_lucro_desejada' => 0.35,
            'aliquota_efetiva' => 0.06,
            'volume_vendas_esperado' => 120,
        ]);

        return [$user, $loja];
    }

    public function test_retorna_erro_quando_usuario_nao_possui_loja(): void
    {
        $user = User::create([
            'name' => 'Usuario Sem Loja',
            'email' => 'semloja@maremu.com',
            'password' => bcrypt('password'),
        ]);

        $response = $this->actingAs($user)->getJson('/api/relatorios');

        $response->assertStatus(404);
    }

    public function test_retorna_resumo_zerado_quando_loja_nao_possui_produtos(): void
    {
        [$user, $loja] = $this->autenticarComLoja();

        $response = $this->actingAs($user)->getJson('/api/relatorios');

        $response->assertOk()
            ->assertJsonPath('resumo.total_unidades_estoque', 0)
            ->assertJsonPath('resumo.liquidez_estoque', 0)
            ->assertJsonPath('resumo.lucro_medio_peca', 0)
            ->assertJsonPath('resumo.fonte_lucro_categoria', 'estoque_potencial')
            ->assertJsonCount(0, 'alertas_criticos')
            ->assertJsonCount(0, 'curva_abc');
    }

    public function test_calcula_lucro_unitario_e_percentual_corretamente(): void
    {
        [$user, $loja] = $this->autenticarComLoja();
        $categoria = Categoria::create(['nome' => 'Camisetas']);

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Camiseta Basica',
            'status' => 'ativo',
            'custo_aquisicao' => 20.00,
            'frete_entrada_unitario' => 2.00,
            'preco_venda_atual' => 44.00,
        ]);

        VarianteProduto::create([
            'produto_id' => $produto->id,
            'tamanho' => 'M',
            'quantidade_estoque' => 10,
            'estoque_minimo_alerta' => 3,
        ]);

        $response = $this->actingAs($user)->getJson('/api/relatorios');

        $response->assertOk();

        $response->assertJsonFragment([
            'produto' => 'Camiseta Basica',
            'custo' => 22.0,
            'venda' => 44.0,
            'lucro_unitario' => 22.0,
            'margem_percentual' => 100.0,
            'estoque' => 10,
            'valor_estoque' => 440.0,
            'lucro_potencial' => 220.0,
        ]);
    }

    public function test_gera_alerta_quando_estoque_igual_ou_abaixo_do_minimo(): void
    {
        [$user, $loja] = $this->autenticarComLoja();
        $categoria = Categoria::create(['nome' => 'Calças']);

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Calca Jeans',
            'status' => 'ativo',
            'custo_aquisicao' => 40.00,
            'frete_entrada_unitario' => 0,
        ]);

        VarianteProduto::create([
            'produto_id' => $produto->id,
            'tamanho' => 'G',
            'quantidade_estoque' => 2,
            'estoque_minimo_alerta' => 5,
        ]);

        $response = $this->actingAs($user)->getJson('/api/relatorios');

        $response->assertOk()
            ->assertJsonCount(1, 'alertas_criticos')
            ->assertJsonFragment([
                'produto' => 'Calca Jeans',
                'tamanho' => 'G',
                'quantidade' => 2,
                'estoque_minimo' => 5,
                'mensagem' => 'Calca Jeans - Tamanho G está com 2 unidades',
            ]);
    }

    public function test_nao_gera_alerta_quando_estoque_acima_do_minimo(): void
    {
        [$user, $loja] = $this->autenticarComLoja();
        $categoria = Categoria::create(['nome' => 'Geral']);

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Estoque Bom',
            'status' => 'ativo',
            'custo_aquisicao' => 10.00,
            'frete_entrada_unitario' => 0,
        ]);

        VarianteProduto::create([
            'produto_id' => $produto->id,
            'tamanho' => 'U',
            'quantidade_estoque' => 50,
            'estoque_minimo_alerta' => 5,
        ]);

        $response = $this->actingAs($user)->getJson('/api/relatorios');

        $response->assertOk()->assertJsonCount(0, 'alertas_criticos');
    }

    public function test_curva_abc_agrupa_itens_excedentes_em_outros(): void
    {
        [$user, $loja] = $this->autenticarComLoja();
        $categoria = Categoria::create(['nome' => 'Variados']);

        for ($i = 1; $i <= 10; $i++) {
            $produto = Produto::create([
                'loja_id' => $loja->id,
                'categoria_id' => $categoria->id,
                'nome' => 'Produto ' . $i,
                'status' => 'ativo',
                'custo_aquisicao' => 10,
                'frete_entrada_unitario' => 0,
                'preco_venda_atual' => 100 - $i, 
            ]);

            VarianteProduto::create([
                'produto_id' => $produto->id,
                'tamanho' => 'M',
                'quantidade_estoque' => 5,
                'estoque_minimo_alerta' => 0,
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/relatorios');

        $response->assertOk();

        $curva = $response->json('curva_abc');

        $this->assertCount(8, $curva);
        $this->assertSame('Outros (3)', end($curva)['produto']);

        $this->assertEquals(100.0, end($curva)['percentual_acumulado']);
    }

    public function test_usa_lucro_potencial_por_categoria_quando_nao_ha_vendas_registradas(): void
    {
        [$user, $loja] = $this->autenticarComLoja();
        $categoria = Categoria::create(['nome' => 'Vestidos']);

        $produto = Produto::create([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Vestido Longo',
            'status' => 'ativo',
            'custo_aquisicao' => 30,
            'frete_entrada_unitario' => 0,
            'preco_venda_atual' => 60,
        ]);

        VarianteProduto::create([
            'produto_id' => $produto->id,
            'tamanho' => 'U',
            'quantidade_estoque' => 4,
            'estoque_minimo_alerta' => 0,
        ]);

        $response = $this->actingAs($user)->getJson('/api/relatorios');

        $response->assertOk()
            ->assertJsonPath('resumo.fonte_lucro_categoria', 'estoque_potencial')
            ->assertJsonFragment([
                'categoria' => 'Vestidos',
                'lucro' => 120.0,
                'percentual' => 100.0,
            ]);
    }
}