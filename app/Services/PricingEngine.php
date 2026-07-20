<?php

namespace App\Services;

/**
 * PricingEngine
 *
 * Motor determinístico de precificação do Maremu.
 * Todas as funções são puras: recebem dados, devolvem números.
 * Nenhuma dependência de banco de dados, IA ou efeitos colaterais.
 *
 * Glossário de parâmetros recorrentes:
 *   $custoAquisicao       — preço de compra do produto
 *   $freteEntradaUnitario — frete de entrada já rateado por unidade
 *   $aliquotaEfetiva      — % de imposto sobre a venda (ex: 0.06 = 6%)
 *   $taxaCanal            — % de taxa do canal de venda (cartão, marketplace)
 *   $custoFixoMensal      — total dos custos fixos da loja por mês
 *   $volumeVendasEsperado — estimativa de unidades vendidas por mês (para ratear custo fixo)
 *   $margemLucroDesejada  — % de lucro desejado (ex: 0.30 = 30%); 0.0 para calcular o preço piso
 */
class PricingEngine
{
    // ─────────────────────────────────────────────────────────────────────
    // 1. MARKUP DIVISOR / MULTIPLICADOR
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Calcula o Markup Divisor.
     *
        * Fórmula:
        *   MarkupDivisor = 1 - (aliquotaEfetiva + taxaCanal + margemLucroDesejada)
     *
     * onde:
        *   despesasVariaveis       = aliquotaEfetiva(%) + taxaCanal(%)
        *   custoFixoMensal         = tratado separadamente como valor absoluto por unidade
     *
     * Retorna o divisor como decimal (ex: 0.54).
     * Lança exceção se o resultado for ≤ 0 (margens insustentáveis).
     *
     * @param  float  $aliquotaEfetiva      % como decimal (ex: 0.06)
     * @param  float  $taxaCanal            % como decimal (ex: 0.03)
     * @param  float  $custoFixoMensal      valor em reais
     * @param  int    $volumeVendasEsperado unidades/mês
     * @param  float  $custoBase            custo unitário total (aquisição + frete)
     * @param  float  $margemLucroDesejada  % como decimal (ex: 0.30); 0.0 para preço piso
     * @return float
     */
    public function markupDivisor(
        float $aliquotaEfetiva,
        float $taxaCanal,
        float $custoFixoMensal,
        int   $volumeVendasEsperado,
        float $custoBase,
        float $margemLucroDesejada
    ): float {
        $this->guard($volumeVendasEsperado > 0, 'volumeVendasEsperado deve ser maior que zero.');
        $this->guard($custoBase > 0, 'custoBase deve ser maior que zero.');

        $divisor = 1 - ($aliquotaEfetiva + $taxaCanal + $margemLucroDesejada);

        $this->guard($divisor > 0, 'Markup Divisor resultou em valor ≤ 0. Revise margens, impostos ou custo fixo.');

        return $divisor;
    }

    /**
     * Calcula o Preço de Venda usando o Markup Divisor.
     *
     * Fórmula: PrecoVenda = CustoBase / MarkupDivisor
     *
     * @param  float  $custoAquisicao
     * @param  float  $freteEntradaUnitario
     * @param  float  $aliquotaEfetiva
     * @param  float  $taxaCanal
     * @param  float  $custoFixoMensal
     * @param  int    $volumeVendasEsperado
     * @param  float  $margemLucroDesejada   0.0 para calcular o PREÇO PISO
     * @return array{preco_venda: float, preco_piso: float, markup_divisor: float, custo_base: float}
     */
    public function calcularPreco(
        float $custoAquisicao,
        float $freteEntradaUnitario,
        float $aliquotaEfetiva,
        float $taxaCanal,
        float $custoFixoMensal,
        int   $volumeVendasEsperado,
        float $margemLucroDesejada
    ): array {
        $custoBase = $custoAquisicao + $freteEntradaUnitario;
        $custoFixoUnitario = $custoFixoMensal / $volumeVendasEsperado;

        $divisor    = $this->markupDivisor($aliquotaEfetiva, $taxaCanal, $custoFixoMensal, $volumeVendasEsperado, $custoBase, $margemLucroDesejada);
        $precoVenda = ($custoBase + $custoFixoUnitario) / $divisor;

        // Preço piso: margem = 0 (ponto onde não há lucro nem prejuízo)
        $divisorPiso = $this->markupDivisor($aliquotaEfetiva, $taxaCanal, $custoFixoMensal, $volumeVendasEsperado, $custoBase, 0.0);
        $precoPiso   = ($custoBase + $custoFixoUnitario) / $divisorPiso;

        return [
            'custo_base'      => round($custoBase, 2),
            'markup_divisor'  => round($divisor, 6),
            'preco_piso'      => round($precoPiso, 2),
            'preco_venda'     => round($precoVenda, 2),
        ];
    }

    /**
     * Calcula o Markup Multiplicador (inverso do divisor).
     *
     * Fórmula: MarkupMultiplicador = 1 / MarkupDivisor
     * Uso:     PrecoVenda = CustoBase × MarkupMultiplicador
     */
    public function markupMultiplicador(
        float $aliquotaEfetiva,
        float $taxaCanal,
        float $custoFixoMensal,
        int   $volumeVendasEsperado,
        float $custoBase,
        float $margemLucroDesejada
    ): float {
        $divisor = $this->markupDivisor($aliquotaEfetiva, $taxaCanal, $custoFixoMensal, $volumeVendasEsperado, $custoBase, $margemLucroDesejada);
        return round(1 / $divisor, 6);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. MARGEM DE CONTRIBUIÇÃO
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Calcula a Margem de Contribuição unitária (em reais).
     *
     * Fórmula: MC = PrecoVenda - CustosVariaveisUnitarios
     *
     * Custos variáveis unitários:
     *   custo de aquisição + frete + (precoVenda × aliquotaEfetiva) + (precoVenda × taxaCanal)
     *
     * Usada tanto nos relatórios quanto como guardrail: se MC for negativa
     * ou zero, o preço escolhido não cobre nem os custos variáveis.
     *
     * @return array{mc_unitaria: float, mc_percentual: float, situacao: string}
     */
    public function margemContribuicao(
        float $precoVenda,
        float $custoAquisicao,
        float $freteEntradaUnitario,
        float $aliquotaEfetiva,
        float $taxaCanal
    ): array {
        $this->guard($precoVenda > 0, 'precoVenda deve ser maior que zero.');

        $custosVariaveis = $custoAquisicao
            + $freteEntradaUnitario
            + ($precoVenda * $aliquotaEfetiva)
            + ($precoVenda * $taxaCanal);

        $mcUnitaria   = $precoVenda - $custosVariaveis;
        $mcPercentual = ($mcUnitaria / $precoVenda) * 100;

        $situacao = match (true) {
            $mcUnitaria < 0  => 'prejuizo_variavel',   // preço nem cobre os custos variáveis
            $mcUnitaria == 0 => 'equilibrio_variavel',
            $mcUnitaria > 0  => 'positiva',
        };

        return [
            'mc_unitaria'   => round($mcUnitaria, 2),
            'mc_percentual' => round($mcPercentual, 2),
            'situacao'      => $situacao,
        ];
    }

    /**
     * Guardrail de checagem: retorna true se o preço escolhido é seguro.
     * Usado antes de aceitar o preço final (IA ou manual) no cadastro do produto.
     *
     * @param  float $precoPiso    resultado de calcularPreco()['preco_piso']
     * @param  float $precoFinal   preço que o lojista quer praticar
     * @return array{seguro: bool, abaixo_do_piso: bool, diferenca: float}
     */
    public function guardrailPreco(float $precoPiso, float $precoFinal): array
    {
        $abaixo    = $precoFinal < $precoPiso;
        $diferenca = round($precoFinal - $precoPiso, 2);

        return [
            'seguro'          => !$abaixo,
            'abaixo_do_piso'  => $abaixo,
            'diferenca'       => $diferenca, // negativo = quanto está abaixo do piso
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. PONTO DE EQUILÍBRIO  (indicador de saúde da loja, não do produto)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Calcula o Ponto de Equilíbrio em unidades e em faturamento.
     *
     * Fórmulas:
     *   PE (unidades)     = CustoFixoTotal / MCUnitaria
     *   PE (faturamento)  = CustoFixoTotal / IndiceMC
     *   IndiceMC          = MCUnitaria / PrecoVenda
     *
     * Responde: "quantas peças preciso vender no mês para não ter prejuízo?"
     *
     * @param  float  $custoFixoMensal    total dos custos fixos do período
     * @param  float  $mcUnitaria         resultado de margemContribuicao()['mc_unitaria']
     * @param  float  $precoVenda         preço de venda praticado
     * @return array{pe_unidades: float, pe_faturamento: float, indice_mc: float}
     */
    public function pontoEquilibrio(
        float $custoFixoMensal,
        float $mcUnitaria,
        float $precoVenda
    ): array {
        $this->guard($mcUnitaria > 0, 'Margem de Contribuição deve ser positiva para calcular o Ponto de Equilíbrio.');
        $this->guard($precoVenda > 0, 'precoVenda deve ser maior que zero.');

        $indiceMC      = $mcUnitaria / $precoVenda;
        $peUnidades    = $custoFixoMensal / $mcUnitaria;
        $peFaturamento = $custoFixoMensal / $indiceMC;

        return [
            'pe_unidades'    => round($peUnidades, 2),
            'pe_faturamento' => round($peFaturamento, 2),
            'indice_mc'      => round($indiceMC, 4),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. MARGEM DE LUCRO LÍQUIDA  (indicador de saúde da loja)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Calcula a Margem de Lucro Líquida do período.
     *
     * Fórmula:
     *   LucroLiquido   = FaturamentoTotal - CustosTotais
     *   MargemLiquida% = (LucroLiquido / FaturamentoTotal) × 100
     *
     * CustosTotais = CMV (custo das mercadorias vendidas)
     *              + CustoFixoMensal
     *              + Impostos (faturamento × aliquotaEfetiva)
     *              + TaxasCanal (faturamento × taxaCanal)
     *
     * @param  float  $faturamentoTotal   soma de (precoVendido × qtd) no período
     * @param  float  $cmvTotal           soma de (custoUnitario × qtd vendida) no período
     * @param  float  $custoFixoMensal
     * @param  float  $aliquotaEfetiva    % como decimal
     * @param  float  $taxaCanal          % como decimal (média ponderada se múltiplos canais)
     * @return array{lucro_liquido: float, margem_liquida_percentual: float, situacao: string}
     */
    public function margemLucroLiquida(
        float $faturamentoTotal,
        float $cmvTotal,
        float $custoFixoMensal,
        float $aliquotaEfetiva,
        float $taxaCanal
    ): array {
        $this->guard($faturamentoTotal > 0, 'faturamentoTotal deve ser maior que zero.');

        $impostos    = $faturamentoTotal * $aliquotaEfetiva;
        $taxasCanal  = $faturamentoTotal * $taxaCanal;
        $custosTotais = $cmvTotal + $custoFixoMensal + $impostos + $taxasCanal;

        $lucroLiquido     = $faturamentoTotal - $custosTotais;
        $margemPercentual = ($lucroLiquido / $faturamentoTotal) * 100;

        $situacao = match (true) {
            $lucroLiquido < 0  => 'prejuizo',
            $lucroLiquido == 0 => 'equilibrio',
            default            => 'lucro',
        };

        return [
            'lucro_liquido'             => round($lucroLiquido, 2),
            'margem_liquida_percentual' => round($margemPercentual, 2),
            'situacao'                  => $situacao,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. ROI DO ESTOQUE  (indicador de saúde do investimento em estoque)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Calcula o ROI do Estoque para um produto ou categoria.
     *
     * Fórmula:
     *   ROI = (LucroObtido / CustoEstoqueInvestido) × 100
     *
     * LucroObtido          = soma de (precoVendido - custoUnitario) × qtd  no período
     * CustoEstoqueInvestido = custoUnitario × quantidadeEmEstoque  (no início do período)
     *
     * Responde: "para cada R$ 1 investido nesse estoque, quanto voltou de lucro?"
     *
     * @param  float  $lucroObtido             lucro das vendas do período para o produto/categoria
     * @param  float  $custoEstoqueInvestido   custo do estoque inicial do período
     * @return array{roi_percentual: float, situacao: string}
     */
    public function roiEstoque(float $lucroObtido, float $custoEstoqueInvestido): array
    {
        $this->guard($custoEstoqueInvestido > 0, 'custoEstoqueInvestido deve ser maior que zero.');

        $roi = ($lucroObtido / $custoEstoqueInvestido) * 100;

        $situacao = match (true) {
            $roi < 0   => 'negativo',
            $roi === 0.0 => 'neutro',
            $roi < 20  => 'baixo',    // referência: abaixo de 20% é sinal de estoque parado
            $roi < 50  => 'adequado',
            default    => 'alto',
        };

        return [
            'roi_percentual' => round($roi, 2),
            'situacao'       => $situacao,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // UTILITÁRIOS PRIVADOS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Guard assertion — lança \InvalidArgumentException com mensagem descritiva.
     */
    private function guard(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \InvalidArgumentException("[PricingEngine] {$message}");
        }
    }
}