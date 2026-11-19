<?php

namespace Tests\Unit;

use App\Services\RecomendacaoCenarioService as R;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Regras da recomendação de cenário, com sinais montados à mão.
 *
 * Limiares de config/recomendacao.php: dias sem venda ≥ 2 × referência,
 * mínimo de 3 unidades, razão ≥ 1,5 liquidação e ≤ 0,6 alta demanda.
 */
class RecomendacaoCenarioServiceTest extends TestCase
{
    private R $servico;

    private CarbonImmutable $hoje;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servico = new R(require __DIR__.'/../../config/recomendacao.php');
        $this->hoje = CarbonImmutable::parse('2026-11-19');
    }

    // ─── regras ──────────────────────────────────────────────────────────

    public function test_estoque_zero_nao_recomenda(): void
    {
        $avaliacao = $this->avaliar(estoque: 0);

        $this->assertFalse($avaliacao['disponivel']);
        $this->assertSame(R::SEM_ESTOQUE, $avaliacao['motivo_indisponivel']);
        $this->assertNull($avaliacao['cenario_recomendado']);
    }

    public function test_estoque_zero_nao_recomenda_nem_com_muitos_dias_sem_venda(): void
    {
        $avaliacao = $this->avaliar(estoque: 0, diasSemVenda: 400, unidades: 0, giroProduto: null);

        $this->assertFalse($avaliacao['disponivel']);
        $this->assertSame(R::SEM_ESTOQUE, $avaliacao['motivo_indisponivel']);
    }

    public function test_sem_referencia_nao_recomenda(): void
    {
        $avaliacao = $this->servico->avaliar($this->sinais(diasSemVenda: 400), null);

        $this->assertFalse($avaliacao['disponivel']);
        $this->assertSame(R::SEM_REFERENCIA, $avaliacao['motivo_indisponivel']);
        $this->assertSame(R::SEM_RECOMENDACAO, $avaliacao['origem_recomendacao']);
    }

    public function test_dias_sem_venda_exatamente_no_dobro_da_referencia_e_liquidacao(): void
    {
        $avaliacao = $this->avaliar(diasSemVenda: 36, unidades: 1, giroProduto: 90.0, referencia: 18.0);

        $this->assertSame(R::LIQUIDACAO, $avaliacao['cenario_recomendado']);
        $this->assertSame(
            'Este produto está há 36 dias sem venda; nesta categoria, sua loja vende uma peça a cada 18 dias.',
            $avaliacao['motivo'],
        );
        // Com 1 unidade o giro do produto não é confiável: não sai no sinal.
        $this->assertNull($avaliacao['sinais']['giro_produto_dias']);
        $this->assertNull($avaliacao['sinais']['razao_giro_produto']);
    }

    public function test_um_dia_abaixo_do_dobro_nao_dispara_a_regra_de_dias(): void
    {
        $avaliacao = $this->avaliar(diasSemVenda: 35, unidades: 1, giroProduto: 90.0, referencia: 18.0);

        $this->assertFalse($avaliacao['disponivel']);
        $this->assertSame(R::DADOS_INSUFICIENTES, $avaliacao['motivo_indisponivel']);
    }

    public function test_produto_que_nunca_vendeu_tem_motivo_proprio(): void
    {
        $avaliacao = $this->servico->avaliar(
            $this->sinais(diasSemVenda: 50, unidades: 0, giroProduto: null) + ['nunca_vendeu' => true],
            $this->referencia(20.0),
        );

        $this->assertSame(R::LIQUIDACAO, $avaliacao['cenario_recomendado']);
        $this->assertStringStartsWith('Este produto nunca vendeu e está há 50 dias em estoque', $avaliacao['motivo']);
    }

    public function test_dias_sem_venda_tem_prioridade_sobre_a_razao_de_giro(): void
    {
        // Razão 0,5 pediria alta demanda, mas o produto parou de vender.
        $avaliacao = $this->avaliar(diasSemVenda: 40, unidades: 9, giroProduto: 10.0, referencia: 20.0);

        $this->assertSame(R::LIQUIDACAO, $avaliacao['cenario_recomendado']);
        $this->assertStringContainsString('sem venda', $avaliacao['motivo']);
        $this->assertNull($avaliacao['sinais']['razao_giro_produto']);
    }

    public function test_menos_de_tres_vendas_nunca_recebe_recomendacao_por_razao(): void
    {
        foreach ([0, 1, 2] as $unidades) {
            foreach ([1.0, 18.0, 500.0] as $giroProduto) {
                $avaliacao = $this->avaliar(diasSemVenda: 5, unidades: $unidades, giroProduto: $unidades ? $giroProduto : null, referencia: 18.0);

                $this->assertFalse($avaliacao['disponivel'], "unidades {$unidades}, giro {$giroProduto}");
                $this->assertSame(R::DADOS_INSUFICIENTES, $avaliacao['motivo_indisponivel']);
            }
        }
    }

    public function test_exatamente_tres_vendas_ja_usa_a_razao(): void
    {
        $avaliacao = $this->avaliar(diasSemVenda: 5, unidades: 3, giroProduto: 30.0, referencia: 20.0);

        $this->assertSame(R::LIQUIDACAO, $avaliacao['cenario_recomendado']);
        $this->assertSame(1.5, $avaliacao['sinais']['razao_giro_produto']);
    }

    public function test_razao_exatamente_um_e_meio_e_liquidacao(): void
    {
        $avaliacao = $this->avaliar(giroProduto: 15.0, referencia: 10.0);

        $this->assertSame(R::LIQUIDACAO, $avaliacao['cenario_recomendado']);
        $this->assertSame(
            'Este produto vende uma peça a cada 15 dias; nesta categoria, sua loja vende uma peça a cada 10 dias. '
            .'Ele está girando mais devagar que a categoria.',
            $avaliacao['motivo'],
        );
    }

    public function test_razao_logo_abaixo_de_um_e_meio_e_venda_ideal(): void
    {
        $avaliacao = $this->avaliar(giroProduto: 14.99, referencia: 10.0);

        $this->assertSame(R::IDEAL, $avaliacao['cenario_recomendado']);
        $this->assertSame(1.499, $avaliacao['sinais']['razao_giro_produto']);
    }

    public function test_razao_exatamente_zero_seis_e_alta_demanda(): void
    {
        $avaliacao = $this->avaliar(giroProduto: 10.8, referencia: 18.0);

        $this->assertSame(R::ALTA_DEMANDA, $avaliacao['cenario_recomendado']);
        $this->assertSame(0.6, $avaliacao['sinais']['razao_giro_produto']);
        $this->assertStringEndsWith('Ele está saindo mais rápido que a categoria.', $avaliacao['motivo']);
    }

    public function test_razao_logo_acima_de_zero_seis_e_venda_ideal(): void
    {
        $avaliacao = $this->avaliar(giroProduto: 10.82, referencia: 18.0);

        $this->assertSame(R::IDEAL, $avaliacao['cenario_recomendado']);
    }

    public function test_razao_no_ritmo_da_categoria_e_venda_ideal(): void
    {
        $avaliacao = $this->avaliar(diasSemVenda: 3, unidades: 9, giroProduto: 10.0, referencia: 10.0);

        $this->assertSame(R::IDEAL, $avaliacao['cenario_recomendado']);
        $this->assertSame([
            'dias_sem_venda' => 3,
            'unidades_vendidas_90d' => 9,
            'giro_produto_dias' => 10.0,
            'giro_referencia_dias' => 10.0,
            'razao_giro_produto' => 1.0,
        ], $avaliacao['sinais']);
        $this->assertSame('v1', $avaliacao['versao_regras']);
    }

    public function test_motivo_de_lojas_similares_fala_de_lojas_parecidas(): void
    {
        $avaliacao = $this->servico->avaliar(
            $this->sinais(diasSemVenda: 40),
            ['giro_dias' => 12.5, 'origem' => R::LOJAS_SIMILARES],
        );

        $this->assertSame(R::LOJAS_SIMILARES, $avaliacao['origem_recomendacao']);
        $this->assertStringContainsString('lojas parecidas com a sua vendem uma peça a cada 12,5 dias', $avaliacao['motivo']);
        $this->assertStringNotContainsString('sua loja', $avaliacao['motivo']);
    }

    public function test_mesma_entrada_mesma_saida(): void
    {
        $sinais = $this->sinais(diasSemVenda: 7, unidades: 5, giroProduto: 13.37);
        $referencia = $this->referencia(11.0);

        $primeira = $this->servico->avaliar($sinais, $referencia);
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame($primeira, $this->servico->avaliar($sinais, $referencia));
        }

        $metrica = ['periodo_referencia' => '2026-11-10', 'giro_medio_dias' => '11.00'];
        $this->assertSame(
            $this->servico->referencia($metrica, 6, null, $this->hoje),
            $this->servico->referencia($metrica, 6, null, $this->hoje),
        );
    }

    public function test_resposta_indisponivel_tem_so_os_dois_campos(): void
    {
        $resposta = $this->servico->paraResposta($this->avaliar(estoque: 0));

        $this->assertSame(['disponivel' => false, 'motivo_indisponivel' => R::SEM_ESTOQUE], $resposta);
    }

    public function test_resposta_disponivel_segue_o_formato(): void
    {
        $resposta = $this->servico->paraResposta($this->avaliar(diasSemVenda: 42, unidades: 1, giroProduto: 90.0, referencia: 18.0));

        $this->assertSame(
            ['disponivel', 'cenario_recomendado', 'origem_recomendacao', 'motivo', 'sinais', 'versao_regras'],
            array_keys($resposta),
        );
        $this->assertSame([
            'dias_sem_venda' => 42,
            'unidades_vendidas_90d' => 1,
            'giro_produto_dias' => null,
            'giro_referencia_dias' => 18.0,
            'razao_giro_produto' => null,
        ], $resposta['sinais']);
    }

    // ─── cascata da referência ───────────────────────────────────────────

    public function test_referencia_propria_suficiente_usa_historico_proprio(): void
    {
        $referencia = $this->servico->referencia(
            ['periodo_referencia' => '2026-11-18', 'giro_medio_dias' => '18.00'],
            5,
            $this->camada3(30.0),
            $this->hoje,
        );

        $this->assertSame(['giro_dias' => 18.0, 'origem' => R::HISTORICO_PROPRIO], $referencia);
    }

    public function test_referencia_propria_com_poucos_produtos_cai_para_lojas_similares(): void
    {
        $referencia = $this->servico->referencia(
            ['periodo_referencia' => '2026-11-18', 'giro_medio_dias' => '18.00'],
            4,
            $this->camada3(30.0),
            $this->hoje,
        );

        $this->assertSame(['giro_dias' => 30.0, 'origem' => R::LOJAS_SIMILARES], $referencia);
    }

    public function test_metrica_com_giro_nulo_nao_serve(): void
    {
        $referencia = $this->servico->referencia(
            ['periodo_referencia' => '2026-11-18', 'giro_medio_dias' => null],
            9,
            $this->camada3(30.0),
            $this->hoje,
        );

        $this->assertSame(R::LOJAS_SIMILARES, $referencia['origem']);
    }

    public function test_metrica_com_mais_de_90_dias_nao_serve_de_referencia(): void
    {
        $velha = ['periodo_referencia' => $this->hoje->subDays(91)->toDateString(), 'giro_medio_dias' => '18.00'];

        $this->assertNull($this->servico->referencia($velha, 9, null, $this->hoje));
        $this->assertSame(R::LOJAS_SIMILARES, $this->servico->referencia($velha, 9, $this->camada3(30.0), $this->hoje)['origem']);

        // No limite de 90 dias ainda vale.
        $limite = ['periodo_referencia' => $this->hoje->subDays(90)->toDateString(), 'giro_medio_dias' => '18.00'];
        $this->assertSame(R::HISTORICO_PROPRIO, $this->servico->referencia($limite, 9, null, $this->hoje)['origem']);
    }

    public function test_sem_referencia_propria_nem_camada_3_da_sem_referencia(): void
    {
        $semGiroNaCamada3 = ['disponivel' => true, 'giro' => null];
        $camada3Indisponivel = ['disponivel' => false, 'motivo' => 'amostra_insuficiente'];

        foreach ([null, $semGiroNaCamada3, $camada3Indisponivel] as $faixa) {
            $referencia = $this->servico->referencia(null, 0, $faixa, $this->hoje);
            $this->assertNull($referencia);

            $avaliacao = $this->servico->avaliar($this->sinais(), $referencia);
            $this->assertSame(R::SEM_REFERENCIA, $avaliacao['motivo_indisponivel']);
        }
    }

    // ─── apoio ───────────────────────────────────────────────────────────

    private function avaliar(
        int $estoque = 10,
        int $diasSemVenda = 2,
        int $unidades = 6,
        ?float $giroProduto = 15.0,
        float $referencia = 18.0,
    ): array {
        return $this->servico->avaliar(
            $this->sinais($estoque, $diasSemVenda, $unidades, $giroProduto),
            $this->referencia($referencia),
        );
    }

    private function sinais(int $estoque = 10, int $diasSemVenda = 2, int $unidades = 6, ?float $giroProduto = 15.0): array
    {
        return [
            'estoque' => $estoque,
            'unidades_vendidas_90d' => $unidades,
            'giro_produto_dias' => $giroProduto,
            'dias_sem_venda' => $diasSemVenda,
        ];
    }

    private function referencia(float $giro): array
    {
        return ['giro_dias' => $giro, 'origem' => R::HISTORICO_PROPRIO];
    }

    private function camada3(float $mediana): array
    {
        return ['disponivel' => true, 'giro' => ['p25' => $mediana - 5, 'mediana' => $mediana, 'p75' => $mediana + 5]];
    }
}
