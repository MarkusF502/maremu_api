<?php

namespace App\Services;

/**
 * OnboardingService
 *
 * Recebe as respostas das 4 perguntas de onboarding e devolve os campos
 * pré-preenchidos para a tabela `lojas` e `canais_venda_loja`.
 *
 * Nenhuma dependência de banco — só lookups determinísticos.
 * O Controller salva o resultado; este serviço só calcula.
 *
 * Enums esperados (strings):
 *   faixaFaturamento : 'ate_10k' | 'de_10k_a_30k' | 'de_30k_a_80k' | 'acima_80k'
 *   posicionamento   : 'popular' | 'medio' | 'premium'
 *   regime           : 'simples_nacional' | 'lucro_presumido' | 'lucro_real'
 *   canais[]         : 'loja_fisica' | 'instagram_whatsapp' | 'marketplace' | 'mix'
 */
class OnboardingService
{
    // ─────────────────────────────────────────────────────────────────────
    // TABELAS DE REFERÊNCIA (benchmarks de mercado para varejo de moda BR)
    // Todos os valores podem ser ajustados conforme pesquisa atualizada.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Ponto médio de cada faixa de faturamento (em reais).
     * Usado para estimar volume de vendas e custo fixo.
     */
    private const FATURAMENTO_PONTO_MEDIO = [
        'ate_10k'       => 6_000.00,
        'de_10k_a_30k'  => 20_000.00,
        'de_30k_a_80k'  => 55_000.00,
        'acima_80k'     => 120_000.00,
    ];

    /**
     * Custo fixo mensal típico por faixa de faturamento (em reais).
     * Referência: aluguel + energia + sistema + contador + pró-labore simplificado.
     */
    private const CUSTO_FIXO_POR_FAIXA = [
        'ate_10k'       => 2_000.00,
        'de_10k_a_30k'  => 4_500.00,
        'de_30k_a_80k'  => 10_000.00,
        'acima_80k'     => 22_000.00,
    ];

    /**
     * Volume de vendas mensal estimado por faixa de faturamento (unidades).
     * Base: ticket médio de peça de roupa por posicionamento, cruzado com a faixa.
     * Valor conservador (ponto médio inferior) para não superestimar o divisor.
     */
    private const VOLUME_VENDAS_POR_FAIXA = [
        'ate_10k'       => 40,
        'de_10k_a_30k'  => 120,
        'de_30k_a_80k'  => 300,
        'acima_80k'     => 600,
    ];

    /**
     * Margem de lucro desejada por posicionamento (como decimal).
     * Referência: prática de mercado para varejo de moda no Brasil.
     *   popular  → margens mais apertadas, volume maior
     *   médio    → margem equilibrada
     *   premium  → margem alta, volume menor
     */
    private const MARGEM_POR_POSICIONAMENTO = [
        'popular' => 0.25,  // 25%
        'medio'   => 0.35,  // 35%
        'premium' => 0.50,  // 50%
    ];

    /**
     * Alíquota efetiva estimada por regime tributário (como decimal).
     *
     * Simples Nacional: média do Anexo I (comércio), faixa até R$ 180k/ano.
     *   → ~6% (inclui ICMS, PIS, COFINS, CSLL, IRPJ, CPP condensados na DAS)
     *
     * Lucro Presumido: IRPJ 8% presunção × 15% + CSLL 12% × 9% + PIS 0.65% + COFINS 3%
     *   → ~5.93%, arredondado para 6% para simplificar
     *   Obs: pode variar bastante com ICMS estadual — valor conservador aqui.
     *
     * Lucro Real: depende do lucro efetivo; estimativa conservadora para varejo deficitário
     *   → PIS (1.65%) + COFINS (7.6%) já são ~9.25% antes do IR/CSLL
     *   → Usamos 11% como estimativa para não subestimar a carga.
     *
     * IMPORTANTE: esses valores são ESTIMATIVAS para o pré-preenchimento.
     * O lojista deve confirmar com seu contador a alíquota real.
     */
    private const ALIQUOTA_POR_REGIME = [
        'simples_nacional' => 0.06,   // 6%
        'lucro_presumido'  => 0.08,   // 8%
        'lucro_real'       => 0.11,   // 11%
    ];

    /**
     * Taxa de canal por tipo (como decimal).
     * Referência: valores médios de mercado em 2024/2025 no Brasil.
     *
     *   loja_fisica        → maquininha de cartão, média entre débito/crédito à vista
     *   instagram_whatsapp → maquininha ou link de pagamento, similar à loja física
     *   marketplace        → média Shopee (~14%) e Mercado Livre (~16%), arredondado
     *   mix                → ponderação conservadora de todos os canais
     */
    private const TAXA_POR_CANAL = [
        'loja_fisica'          => 0.030,  // 3.0%
        'instagram_whatsapp'   => 0.035,  // 3.5% (link de pagamento costuma ser um pouco maior)
        'marketplace'          => 0.150,  // 15%
        'mix'                  => 0.070,  // 7% (estimativa de canal misto)
    ];

    // ─────────────────────────────────────────────────────────────────────
    // MÉTODO PRINCIPAL
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Gera os campos pré-preenchidos da loja a partir das 4 respostas do onboarding.
     *
     * @param  string   $faixaFaturamento  'ate_10k' | 'de_10k_a_30k' | 'de_30k_a_80k' | 'acima_80k'
     * @param  string   $posicionamento    'popular' | 'medio' | 'premium'
     * @param  string   $regime            'simples_nacional' | 'lucro_presumido' | 'lucro_real'
     * @param  string[] $canais            ['loja_fisica', 'marketplace', ...]
     *
     * @return array{
     *   loja: array{
     *     faturamento_medio_mensal: float,
     *     custo_fixo_mensal: float,
     *     custo_fixo_origem: string,
     *     volume_vendas_esperado: int,
     *     margem_lucro_desejada: float,
     *     posicionamento: string,
     *     regime_tributario: string,
     *     aliquota_efetiva: float,
     *     aliquota_origem: string,
     *   },
     *   canais: array<array{
     *     canal: string,
     *     taxa_percentual: float,
     *     taxa_origem: string,
     *   }>,
     *   resumo: array{
     *     faixa_faturamento: string,
     *     total_campos_preenchidos: int,
     *     aviso_contador: bool,
     *   }
     * }
     */
    public function inferirDadosDaLoja(
        string $faixaFaturamento,
        string $posicionamento,
        string $regime,
        array  $canais
    ): array {
        $this->validar($faixaFaturamento, $posicionamento, $regime, $canais);

        $dadosLoja   = $this->inferirLoja($faixaFaturamento, $posicionamento, $regime);
        $dadosCanais = $this->inferirCanais($canais);

        return [
            'loja'   => $dadosLoja,
            'canais' => $dadosCanais,
            'resumo' => [
                'faixa_faturamento'        => $faixaFaturamento,
                'total_campos_preenchidos' => count($dadosLoja) + count($dadosCanais),
                // sinaliza ao frontend que a alíquota deve ser confirmada com contador
                'aviso_contador'           => $regime !== 'simples_nacional',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // INFERÊNCIAS INTERNAS
    // ─────────────────────────────────────────────────────────────────────

    private function inferirLoja(string $faixa, string $posicionamento, string $regime): array
    {
        return [
            // Faturamento: ponto médio da faixa escolhida
            'faturamento_medio_mensal' => self::FATURAMENTO_PONTO_MEDIO[$faixa],

            // Custo fixo: benchmark por porte, marcado como estimativa do sistema
            'custo_fixo_mensal'        => self::CUSTO_FIXO_POR_FAIXA[$faixa],
            'custo_fixo_origem'        => 'estimado_pelo_sistema',

            // Volume de vendas: estimativa por faixa (conservadora)
            'volume_vendas_esperado'   => self::VOLUME_VENDAS_POR_FAIXA[$faixa],

            // Margem desejada: benchmark por posicionamento
            'margem_lucro_desejada'    => self::MARGEM_POR_POSICIONAMENTO[$posicionamento],
            'posicionamento'           => $posicionamento,

            // Regime e alíquota
            'regime_tributario'        => $regime,
            'aliquota_efetiva'         => self::ALIQUOTA_POR_REGIME[$regime],
            'aliquota_origem'          => 'estimado_pelo_sistema',
        ];
    }

    private function inferirCanais(array $canais): array
    {
        return array_map(fn(string $canal) => [
            'canal'          => $canal,
            'taxa_percentual' => self::TAXA_POR_CANAL[$canal],
            'taxa_origem'    => 'estimado_pelo_sistema',
        ], $canais);
    }

    // ─────────────────────────────────────────────────────────────────────
    // VALIDAÇÃO
    // ─────────────────────────────────────────────────────────────────────

    private function validar(string $faixa, string $posicionamento, string $regime, array $canais): void
    {
        $faixasValidas         = array_keys(self::FATURAMENTO_PONTO_MEDIO);
        $posicionamentosValidos = array_keys(self::MARGEM_POR_POSICIONAMENTO);
        $regimesValidos        = array_keys(self::ALIQUOTA_POR_REGIME);
        $canaisValidos         = array_keys(self::TAXA_POR_CANAL);

        if (!in_array($faixa, $faixasValidas, true)) {
            throw new \InvalidArgumentException("[OnboardingService] faixaFaturamento inválida: '{$faixa}'. Valores aceitos: " . implode(', ', $faixasValidas));
        }

        if (!in_array($posicionamento, $posicionamentosValidos, true)) {
            throw new \InvalidArgumentException("[OnboardingService] posicionamento inválido: '{$posicionamento}'. Valores aceitos: " . implode(', ', $posicionamentosValidos));
        }

        if (!in_array($regime, $regimesValidos, true)) {
            throw new \InvalidArgumentException("[OnboardingService] regime inválido: '{$regime}'. Valores aceitos: " . implode(', ', $regimesValidos));
        }

        if (empty($canais)) {
            throw new \InvalidArgumentException("[OnboardingService] Ao menos um canal de venda deve ser informado.");
        }

        foreach ($canais as $canal) {
            if (!in_array($canal, $canaisValidos, true)) {
                throw new \InvalidArgumentException("[OnboardingService] canal inválido: '{$canal}'. Valores aceitos: " . implode(', ', $canaisValidos));
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // UTILITÁRIO: taxa efetiva do canal principal para o PricingEngine
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Quando a loja opera múltiplos canais, o PricingEngine precisa de uma
     * taxa única para o cálculo do Markup Divisor.
     *
     * Retorna a maior taxa entre os canais ativos — abordagem conservadora:
     * garante que o preço mínimo calculado cobre o canal mais caro.
     * O lojista pode ajustar manualmente depois.
     *
     * @param  string[] $canais
     * @return float
     */
    public function taxaCanalConservadora(array $canais): float
    {
        $taxas = array_map(fn($c) => self::TAXA_POR_CANAL[$c] ?? 0.0, $canais);
        return max($taxas);
    }

    /**
     * Descrição legível de cada campo pré-preenchido, para o tooltip/ícone
     * de ajuda que o frontend vai exibir ao lado de cada campo.
     *
     * @return array<string, string>
     */
    public function descricoesDosTooltips(): array
    {
        return [
            'faturamento_medio_mensal' => 'Calculado com base na faixa de faturamento que você informou. Ajuste se souber o valor exato.',
            'custo_fixo_mensal'        => 'Estimativa para lojas do seu porte. Some aluguel + energia + internet + sistema + contador para chegar ao valor real.',
            'volume_vendas_esperado'   => 'Estimativa de quantas peças sua loja vende por mês. Ajuste conforme sua realidade.',
            'margem_lucro_desejada'    => 'Sugestão baseada no posicionamento da sua loja. Você pode ajustar conforme sua meta de lucro.',
            'aliquota_efetiva'         => 'Estimativa com base no seu regime tributário. Confirme com seu contador a alíquota real da sua DAS.',
            'taxa_canal_loja_fisica'   => 'Taxa média de maquininha de cartão. Confira o percentual exato no contrato da sua operadora.',
            'taxa_canal_marketplace'   => 'Média entre Shopee (~14%) e Mercado Livre (~16%). Ajuste conforme o marketplace que você usa.',
            'taxa_canal_instagram_whatsapp' => 'Taxa média para link de pagamento. Confira com seu provedor de pagamento.',
        ];
    }
}