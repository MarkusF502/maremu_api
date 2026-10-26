<?php

namespace Tests\Feature;

use App\Models\Loja;
use App\Models\User;
use App\Services\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SimularPrecificacaoTest extends TestCase
{
    use RefreshDatabase;

    private function dados(): array
    {
        return ['margem' => 0.20, 'custo_base' => 50, 'custo_fixo_mensal' => 2700,
            'volume_vendas_esperado' => 180, 'aliquota_efetiva' => 0.06, 'taxa_canal' => 0.035];
    }

    public function test_exige_autenticacao(): void
    {
        $this->postJson('/api/precificacao/simular', $this->dados())->assertUnauthorized();
    }

    public function test_sem_loja_calcula_com_mesma_formula_do_motor(): void
    {
        $user = User::factory()->create();
        $resposta = $this->actingAs($user)->postJson('/api/precificacao/simular', $this->dados())
            ->assertOk()->assertExactJson([
                'viavel' => true, 'preco' => 92.20, 'preco_piso' => 71.82,
                'multiplo_do_custo' => 1.84, 'lucro_por_peca' => 18.44, 'lucro_a_cada_100' => 20,
                'composicao' => ['preco_piso' => 71.82, 'custo_base' => 50,
                    'custo_fixo_unitario' => 15, 'percentual_variavel' => 0.095],
            ]);
        $motor = new PricingEngine;
        $this->assertEquals($motor->calcularPreco(50, 0, 0.06, 0.035, 2700, 180, 0.20)['preco_venda'], $resposta->json('preco'));
        $this->assertEquals($motor->composicao(50, 0, 0.06, 0.035, 2700, 180), $resposta->json('composicao'));
        $this->assertFalse($user->loja()->exists());
    }

    private function loja(): Loja
    {
        $loja = Loja::factory()->create(['custo_fixo_mensal' => 2700,
            'volume_vendas_esperado' => 180, 'aliquota_efetiva' => 0.06]);
        foreach ([['loja_fisica', 0.02, true], ['marketplace', 0.035, true], ['instagram_whatsapp', 0.20, false]] as [$canal, $taxa, $ativo]) {
            $loja->canais()->create(['canal' => $canal, 'taxa_percentual' => $taxa,
                'taxa_origem' => 'confirmado_pelo_lojista', 'ativo' => $ativo]);
        }
        return $loja;
    }

    public function test_omitidos_vem_da_loja_e_maior_taxa_ativa(): void
    {
        $loja = $this->loja();
        $this->actingAs($loja->user)->postJson('/api/precificacao/simular', ['margem' => 0.20])
            ->assertOk()->assertJsonPath('preco', 92.2)->assertJsonPath('composicao.percentual_variavel', 0.095);
    }

    public function test_valores_explicitos_prevalecem_sobre_loja(): void
    {
        $loja = $this->loja();
        $dados = ['margem' => 0.30, 'custo_base' => 40, 'custo_fixo_mensal' => 1000,
            'volume_vendas_esperado' => 100, 'aliquota_efetiva' => 0.05, 'taxa_canal' => 0.05];
        $preco = (new PricingEngine)->calcularPreco(40, 0, 0.05, 0.05, 1000, 100, 0.30)['preco_venda'];
        $this->actingAs($loja->user)->postJson('/api/precificacao/simular', $dados)
            ->assertOk()->assertJsonPath('preco', $preco);
    }

    public function test_sem_loja_aponta_campos_ausentes_e_aplica_custo_padrao(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/precificacao/simular', ['margem' => 0.20])
            ->assertUnprocessable()->assertJsonValidationErrors(['custo_fixo_mensal', 'volume_vendas_esperado', 'aliquota_efetiva', 'taxa_canal'])
            ->assertJsonMissingValidationErrors(['custo_base', 'margem']);
        $dados = $this->dados();
        unset($dados['custo_base']);
        $this->postJson('/api/precificacao/simular', $dados)->assertOk()->assertJsonPath('composicao.custo_base', 50);
    }

    public function test_sem_canais_ativos_exige_taxa_explicita(): void
    {
        $loja = $this->loja();
        $loja->canais()->update(['ativo' => false]);
        $this->actingAs($loja->user)->postJson('/api/precificacao/simular', ['margem' => 0.20])
            ->assertUnprocessable()->assertJsonValidationErrors(['taxa_canal']);
        $this->postJson('/api/precificacao/simular', ['margem' => 0.20, 'taxa_canal' => 0])
            ->assertOk()->assertJsonPath('viavel', true);
    }

    public function test_inviavel_quando_soma_igual_ou_maior_que_um(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([0.40, 0.50] as $margem) {
            $this->postJson('/api/precificacao/simular', array_replace($this->dados(), [
                'aliquota_efetiva' => 0.40, 'taxa_canal' => 0.20, 'margem' => $margem,
            ]))->assertOk()->assertExactJson(['viavel' => false, 'motivo' => 'margem_inviavel', 'margem_maxima' => 0.40]);
        }
    }

    public function test_limite_inviavel_arredondado_para_baixo(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/api/precificacao/simular', array_replace($this->dados(), [
            'aliquota_efetiva' => 0.4000001, 'taxa_canal' => 0.20, 'margem' => 0.40,
        ]))->assertOk()->assertJsonPath('margem_maxima', 0.399999);
    }

    public function test_rejeita_entradas_invalidas_e_nao_substitui_null_por_padrao(): void
    {
        $this->actingAs($this->loja()->user);
        foreach (['margem' => [null, 0, 0.009, 0.601], 'custo_base' => [null, 0, -1],
            'volume_vendas_esperado' => [0, -1, 1.5], 'custo_fixo_mensal' => [-1],
            'aliquota_efetiva' => [-1, 1.01], 'taxa_canal' => [-1, 1.01]] as $campo => $valores) {
            foreach ($valores as $valor) {
                $this->postJson('/api/precificacao/simular', array_replace($this->dados(), [$campo => $valor]))
                    ->assertUnprocessable()->assertJsonValidationErrors([$campo]);
            }
        }
        $dados = $this->dados();
        unset($dados['margem']);
        $this->postJson('/api/precificacao/simular', $dados)->assertUnprocessable()->assertJsonValidationErrors(['margem']);
    }
}
