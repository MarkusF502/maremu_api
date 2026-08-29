<?php

namespace Tests\Feature;

use App\Models\LogsOnboardingIa;
use App\Models\User;
use App\Services\OnboardingIaInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class OnboardingIaTest extends TestCase
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
                . 'casual com preço acessível. Vendo cerca de 80 peças por mês, faturando em '
                . 'torno de R$ 12 mil. Pago R$ 2.500 de aluguel. Quero ter pelo menos 25% de lucro.',
            'nome_loja'         => 'Maremu Modas',
            'regime_tributario' => 'simples_nacional',
            'canais_marcados'   => ['loja_fisica'],
        ], $overrides);
    }

    private function estimativasFake(): array
    {
        return [
            'confianca_suficiente'   => true,
            'motivo_baixa_confianca' => null,
            'estimativas' => [
                'posicionamento'           => ['valor' => 'popular', 'explicacao' => 'texto menciona preço acessível'],
                'faturamento_medio_mensal' => ['valor' => 12000.0, 'explicacao' => 'texto menciona R$ 12 mil', 'clampado' => false],
                'custo_fixo_mensal'        => ['valor' => 2500.0, 'explicacao' => 'aluguel mencionado', 'clampado' => false],
                'margem_lucro_desejada'    => ['valor' => 0.25, 'explicacao' => 'texto menciona 25% de lucro', 'clampado' => false],
                'volume_vendas_esperado'   => ['valor' => 80, 'explicacao' => 'texto menciona 80 peças/mês', 'clampado' => false],
            ],
            'canais_sugeridos' => [],
        ];
    }

    public function test_fluxo_feliz_retorna_estimativas_e_grava_log(): void
    {
        $this->mock(OnboardingIaInterface::class, function ($mock) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn($this->estimativasFake());
        });

        $response = $this->actingAs($this->user)->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase());

        $response->assertOk()->assertJson(['sucesso' => true]);
        $response->assertJsonStructure(['log_id', 'estimativas', 'canais_sugeridos']);

        $this->assertDatabaseHas('logs_onboarding_ia', [
            'id'            => $response->json('log_id'),
            'user_id'       => $this->user->id,
            'usou_fallback' => false,
        ]);
    }

    public function test_fallback_por_texto_insuficiente_nao_chama_a_ia(): void
    {
        $this->mock(OnboardingIaInterface::class, function ($mock) {
            $mock->shouldNotReceive('estimarDadosLoja');
        });

        $response = $this->actingAs($this->user)->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase([
            'texto_descritivo' => str_repeat('a', 60),
        ]));

        $response->assertOk()->assertJson([
            'sucesso'  => false,
            'fallback' => true,
            'motivo'   => 'texto_insuficiente',
        ]);
    }

    public function test_fallback_por_erro_de_api(): void
    {
        $this->mock(OnboardingIaInterface::class, function ($mock) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andThrow(new RuntimeException('timeout'));
        });

        $response = $this->actingAs($this->user)->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase());

        $response->assertOk()->assertJson([
            'sucesso'  => false,
            'fallback' => true,
            'motivo'   => 'erro_api',
        ]);
    }

    public function test_fallback_por_confianca_insuficiente(): void
    {
        $this->mock(OnboardingIaInterface::class, function ($mock) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn([
                'confianca_suficiente'   => false,
                'motivo_baixa_confianca' => 'texto muito vago',
                'estimativas'            => null,
                'canais_sugeridos'       => [],
            ]);
        });

        $response = $this->actingAs($this->user)->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase());

        $response->assertOk()->assertJson([
            'sucesso'  => false,
            'fallback' => true,
            'motivo'   => 'confianca_insuficiente',
        ]);
    }

    public function test_canal_ja_marcado_e_filtrado_da_sugestao(): void
    {
        // Simula o service já tendo filtrado — mas testamos aqui que o
        // controller propaga o que o service devolveu sem re-sugerir.
        $estimativas = $this->estimativasFake();
        $estimativas['canais_sugeridos'] = ['marketplace'];

        $this->mock(OnboardingIaInterface::class, function ($mock) use ($estimativas) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn($estimativas);
        });

        $response = $this->actingAs($this->user)->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase([
            'canais_marcados' => ['loja_fisica'],
        ]));

        $response->assertOk()->assertJsonPath('canais_sugeridos', ['marketplace']);
    }

    public function test_confirmar_persiste_loja_e_marca_origem_por_campo(): void
    {
        $this->mock(OnboardingIaInterface::class, function ($mock) {
            $mock->shouldReceive('estimarDadosLoja')->once()->andReturn($this->estimativasFake());
        });

        $analise = $this->actingAs($this->user)
            ->postJson('/api/loja/onboarding/analisar-texto', $this->payloadBase())
            ->json();

        $logId = $analise['log_id'];

        // Lojista edita 2 dos 5 campos (custo_fixo_mensal e volume_vendas_esperado)
        $response = $this->actingAs($this->user)->postJson('/api/loja/onboarding/confirmar-ia', [
            'log_id'                   => $logId,
            'nome'                     => 'Maremu Modas',
            'posicionamento'           => 'popular',
            'regime_tributario'        => 'simples_nacional',
            'faturamento_medio_mensal' => 12000,
            'custo_fixo_mensal'        => 3000,   // editado (era 2500)
            'margem_lucro_desejada'    => 0.25,
            'volume_vendas_esperado'   => 100,    // editado (era 80)
            'canais'                   => ['loja_fisica'],
        ]);

        $response->assertCreated()->assertJson(['sucesso' => true]);

        $this->assertDatabaseHas('lojas', [
            'nome'                     => 'Maremu Modas',
            'custo_fixo_mensal'        => 3000.00,
            'custo_fixo_origem'        => 'editado_pelo_lojista',
            'volume_vendas_esperado'   => 100,
        ]);

        $log = LogsOnboardingIa::find($logId);
        $this->assertNotNull($log->loja_id);
        $this->assertNotNull($log->estimativas_finais);
    }
}
