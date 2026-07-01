<?php

namespace Tests\Unit;

use App\Services\PricingEngine;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * PricingEngineTest
 *
 * Testa todas as fórmulas do motor de precificação de forma isolada.
 * Nenhum banco de dados, nenhuma chamada externa — apenas entradas e saídas.
 *
 * Cenário base usado nos testes (loja de roupas, Simples Nacional):
 *   Produto:  camiseta, custo R$ 40,00, frete R$ 3,00 → custo base R$ 43,00
 *   Loja:     alíquota 6%, taxa de canal (maquininha) 3%, custo fixo R$ 3.000/mês
 *   Volume:   150 peças/mês estimadas
 *   Margem:   30% desejada
 */
class PricingEngineTest extends TestCase
{
    private PricingEngine $engine;

    // ── cenário base ──────────────────────────────────────────────────────
    private float $custoAquisicao      = 40.00;
    private float $freteEntrada        = 3.00;   // custo base = 43,00
    private float $aliquota            = 0.06;   // 6% Simples Nacional
    private float $taxaCanal           = 0.03;   // 3% maquininha
    private float $custoFixo           = 3000.00;
    private int   $volume              = 150;
    private float $margemDesejada      = 0.30;   // 30%

    protected function setUp(): void
    {
        $this->engine = new PricingEngine();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. MARKUP DIVISOR
    // ─────────────────────────────────────────────────────────────────────

    public function test_markup_divisor_retorna_valor_correto(): void
    {
        // custoFixoProporcional = (3000/150) / 43 = 20/43 ≈ 0.4651
        // somaDespesas = 0.06 + 0.03 + 0.4651 + 0.30 = 0.8551
        // divisor = 1 - 0.8551 = 0.1449
        $divisor = $this->engine->markupDivisor(
            $this->aliquota,
            $this->taxaCanal,
            $this->custoFixo,
            $this->volume,
            43.00,
            $this->margemDesejada
        );

        $this->assertEqualsWithDelta(0.1449, $divisor, 0.001);
        $this->assertGreaterThan(0, $divisor);
    }

    public function test_markup_divisor_piso_ignora_margem(): void
    {
        $divisorComMargem = $this->engine->markupDivisor(
            $this->aliquota, $this->taxaCanal, $this->custoFixo,
            $this->volume, 43.00, 0.30
        );
        $divisorPiso = $this->engine->markupDivisor(
            $this->aliquota, $this->taxaCanal, $this->custoFixo,
            $this->volume, 43.00, 0.0
        );

        // divisor do piso deve ser MAIOR (menos desconto), gerando preço MENOR
        $this->assertGreaterThan($divisorComMargem, $divisorPiso);
    }

    public function test_markup_divisor_lanca_excecao_se_volume_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->markupDivisor($this->aliquota, $this->taxaCanal, $this->custoFixo, 0, 43.00, $this->margemDesejada);
    }

    public function test_markup_divisor_lanca_excecao_se_custo_base_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->markupDivisor($this->aliquota, $this->taxaCanal, $this->custoFixo, $this->volume, 0.0, $this->margemDesejada);
    }

    public function test_markup_divisor_lanca_excecao_se_despesas_insustentaveis(): void
    {
        // alíquota 60% + taxaCanal 50% = impossível
        $this->expectException(InvalidArgumentException::class);
        $this->engine->markupDivisor(0.60, 0.50, $this->custoFixo, $this->volume, 43.00, 0.30);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. CALCULAR PREÇO (preço de venda + preço piso)
    // ─────────────────────────────────────────────────────────────────────

    public function test_calcular_preco_retorna_estrutura_esperada(): void
    {
        $resultado = $this->engine->calcularPreco(
            $this->custoAquisicao,
            $this->freteEntrada,
            $this->aliquota,
            $this->taxaCanal,
            $this->custoFixo,
            $this->volume,
            $this->margemDesejada
        );

        $this->assertArrayHasKey('custo_base', $resultado);
        $this->assertArrayHasKey('markup_divisor', $resultado);
        $this->assertArrayHasKey('preco_piso', $resultado);
        $this->assertArrayHasKey('preco_venda', $resultado);
    }

    public function test_preco_venda_maior_que_preco_piso(): void
    {
        $resultado = $this->engine->calcularPreco(
            $this->custoAquisicao, $this->freteEntrada,
            $this->aliquota, $this->taxaCanal,
            $this->custoFixo, $this->volume,
            $this->margemDesejada
        );

        $this->assertGreaterThan($resultado['preco_piso'], $resultado['preco_venda']);
    }

    public function test_preco_piso_cobre_custo_base(): void
    {
        $resultado = $this->engine->calcularPreco(
            $this->custoAquisicao, $this->freteEntrada,
            $this->aliquota, $this->taxaCanal,
            $this->custoFixo, $this->volume,
            $this->margemDesejada
        );

        $this->assertGreaterThan($resultado['custo_base'], $resultado['preco_piso']);
    }

    public function test_custo_base_e_soma_de_aquisicao_e_frete(): void
    {
        $resultado = $this->engine->calcularPreco(
            40.00, 3.00,
            $this->aliquota, $this->taxaCanal,
            $this->custoFixo, $this->volume,
            $this->margemDesejada
        );

        $this->assertEquals(43.00, $resultado['custo_base']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. MARKUP MULTIPLICADOR
    // ─────────────────────────────────────────────────────────────────────

    public function test_markup_multiplicador_e_inverso_do_divisor(): void
    {
        $divisor       = $this->engine->markupDivisor($this->aliquota, $this->taxaCanal, $this->custoFixo, $this->volume, 43.00, $this->margemDesejada);
        $multiplicador = $this->engine->markupMultiplicador($this->aliquota, $this->taxaCanal, $this->custoFixo, $this->volume, 43.00, $this->margemDesejada);

        $this->assertEqualsWithDelta(1 / $divisor, $multiplicador, 0.0001);
    }

    public function test_preco_pelo_multiplicador_igual_ao_pelo_divisor(): void
    {
        $custoBase = 43.00;

        $resultadoDivisor = $this->engine->calcularPreco(
            $this->custoAquisicao, $this->freteEntrada,
            $this->aliquota, $this->taxaCanal,
            $this->custoFixo, $this->volume, $this->margemDesejada
        );

        $multiplicador = $this->engine->markupMultiplicador(
            $this->aliquota, $this->taxaCanal,
            $this->custoFixo, $this->volume, $custoBase, $this->margemDesejada
        );

        $precoViaMult = round($custoBase * $multiplicador, 2);
        $this->assertEqualsWithDelta($resultadoDivisor['preco_venda'], $precoViaMult, 0.01);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. MARGEM DE CONTRIBUIÇÃO
    // ─────────────────────────────────────────────────────────────────────

    public function test_margem_contribuicao_positiva(): void
    {
        // preço de venda = R$ 120, custos variáveis = 40 + 3 + 3.6 + 1.8 = 48.40
        // MC unitária = 120 - 48.40 = 71.60 → 59.67%
        $resultado = $this->engine->margemContribuicao(
            precoVenda:            120.00,
            custoAquisicao:        40.00,
            freteEntradaUnitario:  3.00,
            aliquotaEfetiva:       0.03,  // 3% sobre 120 = 3.60
            taxaCanal:             0.015  // 1.5% sobre 120 = 1.80
        );

        $this->assertGreaterThan(0, $resultado['mc_unitaria']);
        $this->assertEquals('positiva', $resultado['situacao']);
        $this->assertEqualsWithDelta(71.60, $resultado['mc_unitaria'], 0.01);
    }

    public function test_margem_contribuicao_negativa_sinaliza_prejuizo(): void
    {
        // custo de aquisição maior que o preço de venda
        $resultado = $this->engine->margemContribuicao(
            precoVenda:           50.00,
            custoAquisicao:       55.00,
            freteEntradaUnitario: 5.00,
            aliquotaEfetiva:      0.06,
            taxaCanal:            0.03
        );

        $this->assertLessThan(0, $resultado['mc_unitaria']);
        $this->assertEquals('prejuizo_variavel', $resultado['situacao']);
    }

    public function test_margem_contribuicao_lanca_excecao_se_preco_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->margemContribuicao(0.0, 40.0, 3.0, 0.06, 0.03);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. GUARDRAIL DE PREÇO
    // ─────────────────────────────────────────────────────────────────────

    public function test_guardrail_aprovado_quando_preco_acima_do_piso(): void
    {
        $resultado = $this->engine->guardrailPreco(precoPiso: 80.00, precoFinal: 95.00);

        $this->assertTrue($resultado['seguro']);
        $this->assertFalse($resultado['abaixo_do_piso']);
        $this->assertEquals(15.00, $resultado['diferenca']);
    }

    public function test_guardrail_reprovado_quando_preco_abaixo_do_piso(): void
    {
        $resultado = $this->engine->guardrailPreco(precoPiso: 80.00, precoFinal: 70.00);

        $this->assertFalse($resultado['seguro']);
        $this->assertTrue($resultado['abaixo_do_piso']);
        $this->assertEquals(-10.00, $resultado['diferenca']);
    }

    public function test_guardrail_aprovado_quando_preco_igual_ao_piso(): void
    {
        $resultado = $this->engine->guardrailPreco(precoPiso: 80.00, precoFinal: 80.00);
        $this->assertTrue($resultado['seguro']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6. PONTO DE EQUILÍBRIO
    // ─────────────────────────────────────────────────────────────────────

    public function test_ponto_equilibrio_calculo_correto(): void
    {
        // MC unitária = R$ 50, preço = R$ 120, custo fixo = R$ 3.000
        // PE unidades  = 3000 / 50 = 60 unidades
        // IndiceMC     = 50 / 120 ≈ 0.4167
        // PE faturamento = 3000 / 0.4167 ≈ R$ 7.200
        $resultado = $this->engine->pontoEquilibrio(
            custoFixoMensal: 3000.00,
            mcUnitaria:      50.00,
            precoVenda:      120.00
        );

        $this->assertEqualsWithDelta(60.0, $resultado['pe_unidades'], 0.1);
        $this->assertEqualsWithDelta(7200.0, $resultado['pe_faturamento'], 1.0);
        $this->assertEqualsWithDelta(0.4167, $resultado['indice_mc'], 0.001);
    }

    public function test_ponto_equilibrio_lanca_excecao_se_mc_zero_ou_negativa(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->pontoEquilibrio(3000.00, 0.0, 120.00);
    }

    public function test_ponto_equilibrio_lanca_excecao_se_preco_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->pontoEquilibrio(3000.00, 50.00, 0.0);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 7. MARGEM DE LUCRO LÍQUIDA
    // ─────────────────────────────────────────────────────────────────────

    public function test_margem_lucro_liquida_com_lucro(): void
    {
        // faturamento = R$ 15.000
        // CMV         = R$ 6.000
        // custo fixo  = R$ 3.000
        // impostos    = 15000 × 0.06 = R$ 900
        // taxas canal = 15000 × 0.03 = R$ 450
        // custos totais = 6000 + 3000 + 900 + 450 = R$ 10.350
        // lucro líquido = 15000 - 10350 = R$ 4.650 → 31%
        $resultado = $this->engine->margemLucroLiquida(
            faturamentoTotal: 15000.00,
            cmvTotal:         6000.00,
            custoFixoMensal:  3000.00,
            aliquotaEfetiva:  0.06,
            taxaCanal:        0.03
        );

        $this->assertEquals(4650.00, $resultado['lucro_liquido']);
        $this->assertEquals(31.00, $resultado['margem_liquida_percentual']);
        $this->assertEquals('lucro', $resultado['situacao']);
    }

    public function test_margem_lucro_liquida_com_prejuizo(): void
    {
        $resultado = $this->engine->margemLucroLiquida(
            faturamentoTotal: 5000.00,
            cmvTotal:         4000.00,
            custoFixoMensal:  3000.00,
            aliquotaEfetiva:  0.06,
            taxaCanal:        0.03
        );

        $this->assertLessThan(0, $resultado['lucro_liquido']);
        $this->assertEquals('prejuizo', $resultado['situacao']);
    }

    public function test_margem_lucro_liquida_lanca_excecao_se_faturamento_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->margemLucroLiquida(0.0, 4000.0, 3000.0, 0.06, 0.03);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 8. ROI DO ESTOQUE
    // ─────────────────────────────────────────────────────────────────────

    public function test_roi_estoque_calculo_correto(): void
    {
        // lucro = R$ 2.000, estoque investido = R$ 5.000
        // ROI = (2000 / 5000) × 100 = 40%
        $resultado = $this->engine->roiEstoque(
            lucroObtido:            2000.00,
            custoEstoqueInvestido:  5000.00
        );

        $this->assertEquals(40.00, $resultado['roi_percentual']);
        $this->assertEquals('adequado', $resultado['situacao']);
    }

    public function test_roi_estoque_negativo_sinaliza_prejuizo(): void
    {
        $resultado = $this->engine->roiEstoque(-500.00, 3000.00);

        $this->assertLessThan(0, $resultado['roi_percentual']);
        $this->assertEquals('negativo', $resultado['situacao']);
    }

    public function test_roi_estoque_alto(): void
    {
        $resultado = $this->engine->roiEstoque(3000.00, 2000.00); // ROI = 150%
        $this->assertEquals('alto', $resultado['situacao']);
    }

    public function test_roi_estoque_lanca_excecao_se_investimento_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->roiEstoque(500.00, 0.0);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 9. CENÁRIO INTEGRADO — fluxo completo do cadastro de produto
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Simula o fluxo real: lojista cadastra produto → sistema calcula preço piso
     * → IA sugere um preço → guardrail valida se o preço sugerido é seguro.
     */
    public function test_fluxo_completo_cadastro_produto(): void
    {
        // 1. Motor calcula preço base e piso
        $precificacao = $this->engine->calcularPreco(
            $this->custoAquisicao,
            $this->freteEntrada,
            $this->aliquota,
            $this->taxaCanal,
            $this->custoFixo,
            $this->volume,
            $this->margemDesejada
        );

        $this->assertGreaterThan(0, $precificacao['preco_piso']);
        $this->assertGreaterThan($precificacao['preco_piso'], $precificacao['preco_venda']);

        // 2. Guardrail: IA sugere um preço acima do piso → deve ser aprovado
        $precoSugeridoIA = $precificacao['preco_venda'] * 1.05; // 5% acima do base
        $guardrailIA = $this->engine->guardrailPreco($precificacao['preco_piso'], $precoSugeridoIA);
        $this->assertTrue($guardrailIA['seguro']);

        // 3. Guardrail: lojista define manualmente um preço abaixo do piso → deve ser reprovado
        $precoManualBaixo = $precificacao['preco_piso'] * 0.80;
        $guardrailManual = $this->engine->guardrailPreco($precificacao['preco_piso'], $precoManualBaixo);
        $this->assertFalse($guardrailManual['seguro']);
        $this->assertTrue($guardrailManual['abaixo_do_piso']);

        // 4. MC para o preço sugerido → deve ser positiva
        $mc = $this->engine->margemContribuicao(
            $precoSugeridoIA,
            $this->custoAquisicao,
            $this->freteEntrada,
            $this->aliquota,
            $this->taxaCanal
        );
        $this->assertEquals('positiva', $mc['situacao']);
    }
}