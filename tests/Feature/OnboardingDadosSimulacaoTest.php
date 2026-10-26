<?php

namespace Tests\Feature;

use App\Models\LogsOnboardingIa;
use App\Models\Loja;
use App\Models\User;
use App\Services\OnboardingIaInterface;
use App\Services\PricingEngine;
use App\Services\TaxaCanalPrecificacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingDadosSimulacaoTest extends TestCase
{
    use RefreshDatabase;

    private const TEXTO = 'Pago 2700 de aluguel. Não tenho funcionários nem outras despesas fixas. Faturo 12000 por mês e vendo 180 peças mensais.';

    private function resultado(): array
    {
        return [
            'confianca_suficiente' => true,
            'estimativas' => ['posicionamento' => ['valor' => 'medio', 'explicacao' => 'Moda casual']],
            'termos_custo_fixo' => [
                'custos_do_local' => ['valor' => 2700, 'origem' => 'explicito', 'citacao' => '2700 de aluguel'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'Não tenho funcionários'],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'nem outras despesas fixas'],
            ],
            'termos_faturamento' => ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'Faturo 12000']],
            'termos_volume_vendas' => ['volume_vendas_direto' => ['valor' => 180, 'origem' => 'explicito', 'citacao' => '180 peças mensais']],
            'canais_sugeridos' => [],
        ];
    }

    private function analisar(array $resultado, string $regime = 'simples_nacional'): array
    {
        $this->mock(OnboardingIaInterface::class, function ($mock) use ($resultado) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn($resultado);
        });
        return $this->actingAs(User::factory()->create())->postJson('/api/loja/onboarding/analisar-texto', [
            'texto_descritivo' => self::TEXTO, 'nome_loja' => 'Loja Teste',
            'regime_tributario' => $regime, 'canais_marcados' => ['loja_fisica', 'instagram_whatsapp'],
        ])->assertOk()->json();
    }

    private function responder(string $sessao, string $id, int|string $valor): array
    {
        return $this->postJson('/api/loja/onboarding/responder-pendencias', [
            'sessao_id' => $sessao, 'respostas' => [['id' => $id, 'resposta' => $valor]],
        ])->assertOk()->json();
    }

    public function test_analise_com_margem_pendente_ja_traz_dados_completos(): void
    {
        $r = $this->analisar($this->resultado());
        $this->assertSame(['margem_lucro_desejada'], array_column($r['pendencias'], 'id'));
        $this->assertEquals(['custo_fixo_mensal' => 2700, 'volume_vendas_esperado' => 180,
            'aliquota_efetiva' => 0.06, 'taxa_canal' => 0.035], $r['dados_simulacao']);
    }

    public function test_nao_expoe_custo_incompleto_nem_volume_pendente(): void
    {
        $resultado = $this->resultado();
        $resultado['termos_custo_fixo']['n_funcionarios'] = ['origem' => 'ausente'];
        $resultado['termos_volume_vendas'] = [];
        $r = $this->analisar($resultado);
        $this->assertSame(['aliquota_efetiva' => 0.06, 'taxa_canal' => 0.035], $r['dados_simulacao']);
        $this->assertNotContains('margem_lucro_desejada', array_column($r['pendencias'], 'id'));

        $r = $this->responder($r['sessao_id'], 'n_funcionarios', 'sim');
        $this->assertSame('custo_total_por_funcionario', $r['pendencias_restantes'][0]['id']);
        $this->assertArrayNotHasKey('custo_fixo_mensal', $r['dados_simulacao']);
        $this->assertArrayNotHasKey('volume_vendas_esperado', $r['dados_simulacao']);

        $r = $this->responder($r['sessao_id'], 'custo_total_por_funcionario', 1000);
        $this->assertSame('volume_vendas_direto', $r['pendencias_restantes'][0]['id']);
        $this->assertEquals(3700, $r['dados_simulacao']['custo_fixo_mensal']);
        $this->assertArrayNotHasKey('volume_vendas_esperado', $r['dados_simulacao']);

        $r = $this->responder($r['sessao_id'], 'volume_vendas_direto', 180);
        $this->assertSame(['margem_lucro_desejada'], array_column($r['pendencias_restantes'], 'id'));
        $this->assertEquals(180, $r['dados_simulacao']['volume_vendas_esperado']);
        $this->assertCount(4, $r['dados_simulacao']);
    }

    public function test_margem_e_ultima_depois_de_volume_e_checagem_cruzada(): void
    {
        $resultado = $this->resultado();
        $resultado['termos_faturamento']['faturamento_direto']['valor'] = 1000;
        $resultado['termos_volume_vendas'] = [];
        $r = $this->analisar($resultado);
        $ordem = [$r['pendencias'][0]['id']];
        $r = $this->responder($r['sessao_id'], 'volume_vendas_direto', 180);
        $ordem[] = $r['pendencias_restantes'][0]['id'];
        $this->assertArrayNotHasKey('custo_fixo_mensal', $r['dados_simulacao']);
        $r = $this->responder($r['sessao_id'], 'checagem_cruzada_custo_faturamento', 'confirmado');
        $ordem[] = $r['pendencias_restantes'][0]['id'];
        $this->assertSame(['volume_vendas_direto', 'checagem_cruzada_custo_faturamento', 'margem_lucro_desejada'], $ordem);
        $this->assertCount(4, $r['dados_simulacao']);
        $this->assertEquals(2700, $r['dados_simulacao']['custo_fixo_mensal']);
        $this->assertEquals(0.06, $r['dados_simulacao']['aliquota_efetiva']);
    }

    public function test_campos_factuais_desconhecidos_nao_sao_substituidos_por_padroes(): void
    {
        $r = $this->analisar($this->resultado());
        $log = LogsOnboardingIa::findOrFail($r['sessao_id']);
        // Sessão sem dados factuais (por exemplo um draft antigo).
        $log->update(['dados_factuais' => []]);
        $r = $this->responder($r['sessao_id'], 'volume_vendas_direto', 180);
        $this->assertArrayNotHasKey('aliquota_efetiva', $r['dados_simulacao']);
        $this->assertArrayNotHasKey('taxa_canal', $r['dados_simulacao']);
    }

    public static function regimes(): array
    {
        return [['simples_nacional'], ['lucro_presumido'], ['lucro_real']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('regimes')]
    public function test_preco_simulado_no_wizard_iguala_motor_para_loja_confirmada(string $regime): void
    {
        $r = $this->analisar($this->resultado(), $regime);
        $dados = $r['dados_simulacao'];
        $preco = $this->postJson('/api/precificacao/simular', $dados + ['margem' => 0.20, 'custo_base' => 50])
            ->assertOk()->assertJsonPath('viavel', true)->json('preco');
        $fim = $this->responder($r['sessao_id'], 'margem_lucro_desejada', 20);
        $this->assertSame('concluido', $fim['status']);
        $this->assertEquals($dados, $fim['dados_simulacao']);
        $confirmacao = $this->postJson('/api/loja/onboarding/confirmar-ia', [
            'log_id' => $r['log_id'], 'nome' => 'Loja Teste', 'posicionamento' => 'medio',
            'regime_tributario' => $regime, 'faturamento_medio_mensal' => $fim['faturamento_medio_mensal'],
            'custo_fixo_mensal' => $fim['custo_fixo_mensal'], 'volume_vendas_esperado' => $fim['volume_vendas_esperado'],
            'margem_lucro_desejada' => $fim['margem_lucro_desejada'], 'canais' => ['loja_fisica', 'instagram_whatsapp'],
        ])->assertCreated();
        $loja = Loja::findOrFail($confirmacao->json('loja_id'));
        $taxa = app(TaxaCanalPrecificacao::class)->obter($loja);
        $this->assertEquals($dados, ['custo_fixo_mensal' => (float) $loja->custo_fixo_mensal,
            'volume_vendas_esperado' => $loja->volume_vendas_esperado,
            'aliquota_efetiva' => (float) $loja->aliquota_efetiva, 'taxa_canal' => $taxa]);
        $calculo = app(PricingEngine::class)->calcularPreco(50, 0, (float) $loja->aliquota_efetiva,
            $taxa, (float) $loja->custo_fixo_mensal, $loja->volume_vendas_esperado, (float) $loja->margem_lucro_desejada);
        $this->assertEquals($preco, $calculo['preco_venda']);
    }
}
