<?php

namespace Tests\Feature;

use App\Models\LogsOnboardingIa;
use App\Models\User;
use App\Services\OnboardingIaInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec-Extracao-Assertiva-Onboarding-Maremu — fluxo ponta a ponta do wizard
 * de pendências: analisar-texto gera pendências → responder-pendencias
 * resolve e calcula os valores finais, sem nunca chamar a IA de novo.
 */
class OnboardingPendenciasTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function payloadBase(array $overrides = []): array
    {
        return array_merge([
            'texto_descritivo'  => 'Tenho uma loja de roupas femininas em Campinas, focada em moda '
                . 'casual com preço acessível. Faturo bem, mas não tenho certeza dos meus custos. '
                . 'Quero ter pelo menos 25% de lucro.',
            'nome_loja'         => 'Maremu Modas',
            'regime_tributario' => 'simples_nacional',
            'canais_marcados'   => ['loja_fisica'],
        ], $overrides);
    }

    private function estimativasComTermosIncompletos(): array
    {
        return [
            'confianca_suficiente'   => true,
            'motivo_baixa_confianca' => null,
            'estimativas' => [
                'posicionamento' => ['valor' => 'popular', 'explicacao' => 'preço acessível'],
            ],
            'termos_custo_fixo' => [
                'custos_do_local'             => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'n_funcionarios'              => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos'         => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'faturo bem'],
            ],
            'termos_faturamento' => [
                'faturamento_direto' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
            ],
            // payloadBase() já menciona "Quero ter pelo menos 25% de lucro" —
            // a margem vem resolvida daqui pra não introduzir uma pendência
            // extra nos testes de fluxo abaixo, que não fazem parte do que
            // eles pretendem cobrir (custo fixo / faturamento).
            'termos_margem_lucro' => [
                'margem_direta' => ['valor' => 25, 'origem' => 'explicito', 'citacao' => '25% de lucro'],
                'preco_custo'   => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'preco_venda'   => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
            ],
            'canais_sugeridos' => [],
        ];
    }

    public function test_analisar_texto_com_termos_incompletos_gera_pendencias_em_ordem(): void
    {
        $this->mock(OnboardingIaInterface::class, function ($mock) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn($this->estimativasComTermosIncompletos());
        });

        $response = $this->actingAs($this->user)->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase());

        $response->assertOk()->assertJson(['sucesso' => true]);
        $response->assertJsonStructure(['sessao_id', 'pendencias']);

        $pendencias = $response->json('pendencias');
        $this->assertNotEmpty($pendencias);
        // custos_do_local vem antes de n_funcionarios (Grupo A, ordem SPEC §6.2)
        $this->assertSame('custos_do_local', $pendencias[0]['id']);

        $log = LogsOnboardingIa::find($response->json('sessao_id'));
        $this->assertSame('pendente', $log->status);
    }

    public function test_responder_pendencias_resolve_e_conclui_sessao(): void
    {
        $this->mock(OnboardingIaInterface::class, function ($mock) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn($this->estimativasComTermosIncompletos());
        });

        $analise = $this->actingAs($this->user)
            ->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase())
            ->json();

        $sessaoId = $analise['sessao_id'];

        // 1) custos_do_local
        $r1 = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
            'sessao_id' => $sessaoId,
            'respostas' => [['id' => 'custos_do_local', 'resposta' => 2500]],
        ])->json();

        $this->assertSame('pendente', $r1['status']);
        $this->assertSame('n_funcionarios', $r1['pendencias_restantes'][0]['id']);

        // 2) n_funcionarios = sim → gera pendência condicional custo_total_por_funcionario
        $r2 = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
            'sessao_id' => $sessaoId,
            'respostas' => [['id' => 'n_funcionarios', 'resposta' => 'sim']],
        ])->json();

        $this->assertSame('pendente', $r2['status']);
        $this->assertSame('custo_total_por_funcionario', $r2['pendencias_restantes'][0]['id']);

        // 3) confirma o custo sugerido pela tabela do regime tributário
        $r3 = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
            'sessao_id' => $sessaoId,
            'respostas' => [['id' => 'custo_total_por_funcionario', 'resposta' => 'confirmado']],
        ])->json();

        $this->assertSame('pendente', $r3['status']);
        // faturamento_direto veio com origem 'ausente' na resposta da IA —
        // a rota real vira "decomposição" (SPEC §3.2), então a próxima
        // pendência é quantidade_vendida, não faturamento_direto.
        $this->assertSame('quantidade_vendida', $r3['pendencias_restantes'][0]['id']);

        // 4) quantidade vendida + ticket médio (rota decomposição, mensal)
        $r4 = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
            'sessao_id' => $sessaoId,
            'respostas' => [['id' => 'quantidade_vendida', 'resposta' => 100]],
        ])->json();
        $this->assertSame('pendente', $r4['status']);
        $this->assertSame('ticket_medio', $r4['pendencias_restantes'][0]['id']);

        $r5 = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
            'sessao_id' => $sessaoId,
            'respostas' => [['id' => 'ticket_medio', 'resposta' => 150]],
        ])->json();

        $this->assertSame('concluido', $r5['status']);
        $this->assertEquals(2500 + 2280.50, $r5['custo_fixo_mensal']);
        $this->assertEquals(15000, $r5['faturamento_medio_mensal']);

        // A IA tinha estimado volume_vendas_esperado=80 isolado do texto
        // (estimativasComTermosIncompletos()), mas a Rota 2 do faturamento
        // foi resolvida com quantidade_vendida=100 — volume_vendas_esperado
        // precisa sincronizar com esse número, não ficar com o chute da IA.
        $this->assertSame(100, $r5['volume_vendas_esperado']);

        $log = LogsOnboardingIa::find($sessaoId);
        $this->assertSame('concluido', $log->status);
        $this->assertSame(100, $log->estimativas_ia['estimativas']['volume_vendas_esperado']['valor']);
    }

    public function test_volume_vendas_esperado_sincroniza_com_quantidade_vendida_da_rota_2(): void
    {
        // Reproduz o bug reportado: texto vago faz a IA estimar
        // volume_vendas_esperado baixo, guardrail clampa pro piso (10); o
        // lojista depois informa via pendência 200 unidades a R$30 — o
        // faturamento (6000) bate, mas volume_vendas_esperado não pode
        // continuar em 10.
        $estimativas = $this->estimativasComTermosIncompletos();
        $estimativas['estimativas']['volume_vendas_esperado'] = ['valor' => 10, 'explicacao' => 'estimativa vaga', 'clampado' => true];

        $this->mock(OnboardingIaInterface::class, function ($mock) use ($estimativas) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn($estimativas);
        });

        $sessaoId = $this->actingAs($this->user)
            ->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase())
            ->json('sessao_id');

        foreach ([
            ['id' => 'custos_do_local', 'resposta' => 2500],
            ['id' => 'n_funcionarios', 'resposta' => 'nao'],
            ['id' => 'quantidade_vendida', 'resposta' => 200],
        ] as $resposta) {
            $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
                'sessao_id' => $sessaoId,
                'respostas' => [$resposta],
            ]);
        }

        $final = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
            'sessao_id' => $sessaoId,
            'respostas' => [['id' => 'ticket_medio', 'resposta' => 30]],
        ])->json();

        $this->assertSame('concluido', $final['status']);
        $this->assertEquals(6000, $final['faturamento_medio_mensal']);
        $this->assertSame(200, $final['volume_vendas_esperado']);
    }

    public function test_rota_1_faturamento_direto_gera_pendencia_de_volume_vendas(): void
    {
        $estimativas = $this->estimativasComTermosIncompletos();
        // Rota 1: faturamento vem direto no texto, sem quantidade —
        // volume_vendas_esperado não tem de onde ser derivado.
        $estimativas['termos_faturamento'] = [
            'faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'faturo bem'],
        ];

        $this->mock(OnboardingIaInterface::class, function ($mock) use ($estimativas) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn($estimativas);
        });

        $sessaoId = $this->actingAs($this->user)
            ->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase())
            ->json('sessao_id');

        foreach ([
            ['id' => 'custos_do_local', 'resposta' => 2500],
            ['id' => 'n_funcionarios', 'resposta' => 'nao'],
        ] as $resposta) {
            $r = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
                'sessao_id' => $sessaoId,
                'respostas' => [$resposta],
            ])->json();
        }

        $this->assertSame('pendente', $r['status']);
        $this->assertSame('volume_vendas_direto', $r['pendencias_restantes'][0]['id']);
        $this->assertSame('confirmacao_valor_faixa', $r['pendencias_restantes'][0]['tipo']);

        $final = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
            'sessao_id' => $sessaoId,
            'respostas' => [['id' => 'volume_vendas_direto', 'resposta' => 60]],
        ])->json();

        $this->assertSame('concluido', $final['status']);
        $this->assertEquals(12000, $final['faturamento_medio_mensal']);
        $this->assertSame(60, $final['volume_vendas_esperado']);
    }

    public function test_margem_lucro_pendencia_combinada_end_to_end(): void
    {
        $estimativas = $this->estimativasComTermosIncompletos();
        // Nenhuma das duas rotas de margem tem dado no texto.
        $estimativas['termos_margem_lucro'] = [
            'margem_direta' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
            'preco_custo'   => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
            'preco_venda'   => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
        ];
        $estimativas['termos_faturamento'] = [
            'faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'faturo bem'],
        ];

        $this->mock(OnboardingIaInterface::class, function ($mock) use ($estimativas) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn($estimativas);
        });

        $sessaoId = $this->actingAs($this->user)
            ->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase())
            ->json('sessao_id');

        $r = null;
        foreach ([
            ['id' => 'custos_do_local', 'resposta' => 2500],
            ['id' => 'n_funcionarios', 'resposta' => 'nao'],
            ['id' => 'volume_vendas_direto', 'resposta' => 60],
        ] as $resposta) {
            $r = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
                'sessao_id' => $sessaoId,
                'respostas' => [$resposta],
            ])->json();
        }

        $this->assertSame('pendente', $r['status']);
        // Pendência única e combinada — nunca preco_custo/preco_venda separados.
        $this->assertSame('margem_lucro_desejada', $r['pendencias_restantes'][0]['id']);
        $this->assertCount(1, $r['pendencias_restantes']);

        $final = $this->actingAs($this->user)->postJson('/api/loja/onboarding/responder-pendencias', [
            'sessao_id' => $sessaoId,
            'respostas' => [['id' => 'margem_lucro_desejada', 'resposta' => 30]],
        ])->json();

        $this->assertSame('concluido', $final['status']);
        $this->assertEqualsWithDelta(0.30, $final['margem_lucro_desejada'], 0.0001);
    }
}
