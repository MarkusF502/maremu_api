<?php

namespace App\Services;

/**
 * MargemLiquidacaoService
 *
 * Calcula deterministicamente a margem de lucro do cenário de liquidação,
 * a partir de uma tabela de score (razao_margem peso 2×, razao_giro peso 1×).
 *
 * Motivação (ver Relatório de Testes de Variância — Módulo de Sugestão de
 * Margens por IA, Sistema Maremu, §6): o cenário de liquidação foi o único,
 * entre os três avaliados, que não convergiu via engenharia de prompt — CV
 * sempre ≥ 9%, mesmo no melhor caso testado (sinais fortes, concordantes e
 * longe de fronteira, teste 6). Os cenários ideal e alta_demanda permanecem
 * com margem livre/float decidida pela IA (CV ≈ 0% em todos os testes) e
 * não são afetados por esta classe.
 *
 * Pura, sem I/O — mesmo padrão do PricingEngine. Recebe dados, devolve
 * números; nenhuma dependência de banco, IA ou efeitos colaterais.
 */
class MargemLiquidacaoService
{
    // Faixas de margem por score (0–6) — ver Relatório §7 (Limitações):
    // limiares definidos por julgamento de negócio, não calibrados
    // estatisticamente ainda.
    private const MARGEM_AGRESSIVA     = 0.10; // score baixo (0–1)
    private const MARGEM_INTERMEDIARIA = 0.12; // score médio (2–4)
    private const MARGEM_CONSERVADORA  = 0.15; // score alto (5–6)

    // Limiares de razao_margem (peso 2) — margem_realizada / margem_planejada
    private const RAZAO_MARGEM_LIMIAR_BAIXO = 0.70;
    private const RAZAO_MARGEM_LIMIAR_ALTO  = 0.90;

    // Limiares de razao_giro (peso 1) — giro real / giro esperado pela loja
    private const RAZAO_GIRO_LIMIAR_BAIXO = 0.8;
    private const RAZAO_GIRO_LIMIAR_ALTO  = 1.5;

    // Margens de fallback por posicionamento — usadas no cold start, quando
    // a loja ainda não tem razao_margem nem razao_giro (sem camada_4 ou sem
    // volume mínimo atingido na categoria).
    private const MARGEM_FALLBACK = [
        'popular' => self::MARGEM_AGRESSIVA,
        'medio'   => self::MARGEM_INTERMEDIARIA,
        'premium' => self::MARGEM_CONSERVADORA,
    ];

    /**
     * Calcula a margem de liquidação e a origem da decisão, para fins de
     * auditoria (ver Relatório §6 — campo destinado a logs_sugestao_ia).
     *
     * @param  float|null  $razaoMargem     camada_4.razao_margem, se disponível
     * @param  float|null  $razaoGiro       camada_4.razao_giro, se disponível
     * @param  string      $posicionamento  loja.posicionamento ('popular'|'medio'|'premium')
     * @return array{margem: float, origem: 'score'|'fallback_posicionamento', score: int|null}
     */
    public function calcular(?float $razaoMargem, ?float $razaoGiro, string $posicionamento): array
    {
        // Cold start: nenhum dos dois sinais disponível — cai no fallback
        // por posicionamento (mesmo caso coberto nos testes 1–2 e 3–4 do
        // relatório, hoje instável via IA e aqui resolvido com CV = 0%).
        if ($razaoMargem === null && $razaoGiro === null) {
            return [
                'margem' => $this->margemFallback($posicionamento),
                'origem' => 'fallback_posicionamento',
                'score'  => null,
            ];
        }

        $score = $this->calcularScore($razaoMargem, $razaoGiro);

        return [
            'margem' => $this->margemPorScore($score),
            'origem' => 'score',
            'score'  => $score,
        ];
    }

    /**
     * Score final na escala 0–6: razao_margem contribui 0/2/4 pontos
     * (peso 2×), razao_giro contribui 0/1/2 pontos (peso 1×).
     *
     * Quando apenas um dos dois sinais está presente (o caso "ambos
     * ausentes" já foi tratado antes de chegar aqui), o score é normalizado
     * para a escala 0–6 usando só o sinal disponível, para não penalizar
     * artificialmente por um dado ausente que a loja ainda não tem.
     */
    private function calcularScore(?float $razaoMargem, ?float $razaoGiro): int
    {
        $pontosMargem = $razaoMargem !== null ? $this->pontosRazaoMargem($razaoMargem) * 2 : null; // 0, 2 ou 4
        $pontosGiro   = $razaoGiro   !== null ? $this->pontosRazaoGiro($razaoGiro) : null;          // 0, 1 ou 2

        if ($pontosMargem !== null && $pontosGiro !== null) {
            return $pontosMargem + $pontosGiro; // 0–6, já na escala final
        }

        if ($pontosMargem !== null) {
            return (int) round(($pontosMargem / 4) * 6);
        }

        return (int) round(($pontosGiro / 2) * 6);
    }

    /**
     * razao_margem = margem_realizada_media / margem_planejada_media.
     *   < 0,70   → 0 pts (rendendo bem abaixo da meta — liquidação agressiva)
     *   0,70–0,90 → 1 pt (levemente abaixo da meta)
     *   ≥ 0,90   → 2 pts (na meta ou acima — liquidação conservadora)
     */
    private function pontosRazaoMargem(float $razaoMargem): int
    {
        return match (true) {
            $razaoMargem < self::RAZAO_MARGEM_LIMIAR_BAIXO => 0,
            $razaoMargem < self::RAZAO_MARGEM_LIMIAR_ALTO  => 1,
            default                                        => 2,
        };
    }

    /**
     * razao_giro = giro_medio_dias / giro esperado pela própria loja.
     *   > 1,5    → 0 pts (girando bem mais devagar — reforça liquidação agressiva)
     *   0,8–1,5  → 1 pt (dentro do esperado)
     *   < 0,8    → 2 pts (girando mais rápido — reforça liquidação conservadora)
     */
    private function pontosRazaoGiro(float $razaoGiro): int
    {
        return match (true) {
            $razaoGiro > self::RAZAO_GIRO_LIMIAR_ALTO   => 0,
            $razaoGiro >= self::RAZAO_GIRO_LIMIAR_BAIXO => 1,
            default                                     => 2,
        };
    }

    /**
     * Score 0–1 → agressiva (10%); 2–4 → intermediária (12%); 5–6 → conservadora (15%).
     */
    private function margemPorScore(int $score): float
    {
        return match (true) {
            $score <= 1 => self::MARGEM_AGRESSIVA,
            $score <= 4 => self::MARGEM_INTERMEDIARIA,
            default     => self::MARGEM_CONSERVADORA,
        };
    }

    private function margemFallback(string $posicionamento): float
    {
        return self::MARGEM_FALLBACK[$posicionamento] ?? self::MARGEM_INTERMEDIARIA;
    }
}