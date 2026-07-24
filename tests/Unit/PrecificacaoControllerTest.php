<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\LogsSugestaoIa;
use App\Models\Loja;
use App\Models\Produto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Testes para PrecificacaoController (sugerir / confirmar).
 */
class PrecificacaoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function autenticarComLojaEProduto(array $atributosProduto = []): array
    {
        // 1. Cria o usuário primeiro
        $user = User::create([
            'name' => 'Usuario Teste',
            'email' => 'teste_' . Str::random(5) . '@maremu.com',
            'password' => bcrypt('password123'),
        ]);

        // 2. Cria a loja passando o ID do usuário
        $loja = Loja::create([
            'user_id' => $user->id,
            'nome' => 'Loja Teste ' . Str::random(5),
            'posicionamento' => 'medio',
            'regime_tributario' => 'simples_nacional',
            'faturamento_medio_mensal' => 20000,
            'custo_fixo_mensal' => 4500,
            'margem_lucro_desejada' => 0.35,
            'aliquota_efetiva' => 0.06,
            'volume_vendas_esperado' => 120,
        ]);

        $categoria = Categoria::create([
            'nome' => 'Categoria Padrão',
        ]);

        $produto = Produto::create(array_merge([
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Produto Teste',
            'status' => 'ativo',
            'custo_aquisicao' => 20.00,
            'frete_entrada_unitario' => 2.00,
            'preco_piso' => 49.90,
        ], $atributosProduto));

        return [$user, $loja, $produto];
    }

    public function test_retorna_422_quando_preco_piso_nao_foi_calculado(): void
    {
        [$user, $loja, $produto] = $this->autenticarComLojaEProduto([
            'preco_piso' => null,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/precificacao/sugerir/{$produto->id}");

        $response->assertStatus(422)
            ->assertJson([
                'code' => 'preco_piso_ausente',
            ]);
    }

    public function test_retorna_404_quando_produto_nao_pertence_a_loja_do_usuario(): void
    {
        $userOutro = User::create([
            'name' => 'Outro Dono',
            'email' => 'outro_' . Str::random(5) . '@maremu.com',
            'password' => bcrypt('password123'),
        ]);

        $lojaOutra = Loja::create([
            'user_id' => $userOutro->id,
            'nome' => 'Outra Loja',
            'posicionamento' => 'popular',
            'regime_tributario' => 'simples_nacional',
            'faturamento_medio_mensal' => 10000,
            'custo_fixo_mensal' => 2000,
            'margem_lucro_desejada' => 0.25,
            'aliquota_efetiva' => 0.06,
            'volume_vendas_esperado' => 50,
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Outra']);

        $produtoDeOutraLoja = Produto::create([
            'loja_id' => $lojaOutra->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Produto Concorrente',
            'custo_aquisicao' => 15.00,
            'frete_entrada_unitario' => 0,
            'preco_piso' => 30,
        ]);

        [$user] = $this->autenticarComLojaEProduto();

        $response = $this->actingAs($user)
            ->postJson("/api/precificacao/sugerir/{$produtoDeOutraLoja->id}");

        $response->assertStatus(404);
    }

    public function test_monta_payload_e_cria_log_quando_preco_piso_existe(): void
    {
        [$user, $loja, $produto] = $this->autenticarComLojaEProduto();

        $response = $this->actingAs($user)
            ->postJson("/api/precificacao/sugerir/{$produto->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'payload' => [
                    'camada_1' => ['preco_piso', 'custo_base', 'custo_origem'],
                    'camada_2' => ['loja', 'canais', 'produto'],
                    'meta' => ['produto_id', 'loja_id', 'categoria_id', 'gerado_em'],
                ],
                'log_id',
                'camada_4_ativa',
                'canais_disponiveis',
            ]);

        $this->assertDatabaseHas('logs_sugestao_ia', [
            'produto_id' => $produto->id,
        ]);
    }

    public function test_confirmar_atualiza_preco_e_origem_do_produto(): void
    {
        [$user, $loja, $produto] = $this->autenticarComLojaEProduto();

        $log = LogsSugestaoIa::create([
            'produto_id' => $produto->id,
            'payload_enviado' => ['fake' => true],
        ]);

        $response = $this->actingAs($user)->postJson('/api/precificacao/confirmar', [
            'log_id' => $log->id,
            'cenario_escolhido' => 'cenario_2',
            'preco_final' => 79.90,
        ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Preço confirmado com sucesso.',
                'produto_id' => $produto->id,
                'preco_final' => 79.90,
            ]);

        $this->assertDatabaseHas('produtos', [
            'id' => $produto->id,
            'preco_venda_atual' => 79.90,
            'preco_origem' => 'ia_cenario_2',
        ]);

        $this->assertDatabaseHas('logs_sugestao_ia', [
            'id' => $log->id,
            'cenario_escolhido' => 'ia',
            'preco_final_escolhido' => 79.90,
        ]);
    }


    public function test_confirmar_com_cenario_manual_marca_origem_manual(): void
    {
        [$user, $loja, $produto] = $this->autenticarComLojaEProduto();

        $log = LogsSugestaoIa::create([
            'produto_id' => $produto->id,
            'payload_enviado' => ['fake' => true],
        ]);

        $response = $this->actingAs($user)->postJson('/api/precificacao/confirmar', [
            'log_id' => $log->id,
            'cenario_escolhido' => 'manual',
            'preco_final' => 65.00,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('produtos', [
            'id' => $produto->id,
            'preco_venda_atual' => 65.00,
            'preco_origem' => 'manual',
        ]);
    }

    public function test_confirmar_bloqueia_log_de_outra_loja(): void
    {
        $userOutro = User::create([
            'name' => 'Outro Dono 2',
            'email' => 'outro2_' . Str::random(5) . '@maremu.com',
            'password' => bcrypt('password123'),
        ]);

        $lojaOutra = Loja::create([
            'user_id' => $userOutro->id,
            'nome' => 'Loja Bloqueada',
            'posicionamento' => 'popular',
            'regime_tributario' => 'simples_nacional',
            'faturamento_medio_mensal' => 5000,
            'custo_fixo_mensal' => 1000,
            'margem_lucro_desejada' => 0.20,
            'aliquota_efetiva' => 0.05,
            'volume_vendas_esperado' => 30,
        ]);

        $categoria = Categoria::create(['nome' => 'Categoria Outra']);

        $produtoDeOutraLoja = Produto::create([
            'loja_id' => $lojaOutra->id,
            'categoria_id' => $categoria->id,
            'nome' => 'Produto Bloqueado',
            'custo_aquisicao' => 10.00,
            'frete_entrada_unitario' => 0,
            'preco_piso' => 30,
        ]);

        $logDeOutraLoja = LogsSugestaoIa::create([
            'produto_id' => $produtoDeOutraLoja->id,
            'payload_enviado' => ['fake' => true],
        ]);

        [$user] = $this->autenticarComLojaEProduto();

        $response = $this->actingAs($user)->postJson('/api/precificacao/confirmar', [
            'log_id' => $logDeOutraLoja->id,
            'cenario_escolhido' => 'cenario_2',
            'preco_final' => 50,
        ]);

        $response->assertStatus(403);
    }

    public function test_confirmar_valida_campos_obrigatorios(): void
    {
        [$user] = $this->autenticarComLojaEProduto();

        $response = $this->actingAs($user)->postJson('/api/precificacao/confirmar', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['log_id', 'cenario_escolhido', 'preco_final']);
    }
}