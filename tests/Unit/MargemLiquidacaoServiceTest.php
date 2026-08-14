<?php

namespace Tests\Unit;

use App\Services\MargemLiquidacaoService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MargemLiquidacaoServiceTest
 *
 * Testa a tabela de score determinística do cenário de liquidação de forma
 * isolada. Nenhum banco de dados, nenhuma chamada externa — apenas entradas
 * e saídas.
 *
 * Motivação (ver Relatório de Testes de Variância — Módulo de Sugestão de
 * Margens por IA, Sistema Maremu): o cenário de liquidação nunca convergiu
 * via engenharia de prompt (CV ≥ 9% em todos os seis testes). Esta classe
 * substitui a decisão livre da IA por uma regra determinística — por
 * construção, CV = 0% garantido. Os cenários usados abaixo espelham os
 * fixtures de app/Console/Commands/TestarVarianciaPrecificacao.php
 * (longe_borda, borda_inferior_margem, borda_superior_margem, cold_start),
 * para que o comportamento determinístico possa ser comparado diretamente
 * com o que a IA fazia nesses mesmos pontos.
 */
class MargemLiquidacaoServiceTest extends TestCase
{
    private MargemLiquidacaoService $service;

    protected function setUp(): void
    {
        $this->service = new MargemLiquidacaoService();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. FALLBACK POR POSICIONAMENTO (cold start — os dois sinais ausentes)
    // ─────────────────────────────────────────────────────────────────────

    public function test_fallback_popular_retorna_margem_agressiva(): void
    {
        $resultado = $this->service->calcular(null, null, 'popular');

        $this->assertEquals(0.10, $resultado['margem']);
        $this->assertEquals('fallback_posicionamento', $resultado['origem']);
        $this->assertNull($resultado['score']);
    }

    public function test_fallback_medio_retorna_margem_intermediaria(): void
    {
        $resultado = $this->service->calcular(null, null, 'medio');

        $this->assertEquals(0.12, $resultado['margem']);
        $this->assertEquals('fallback_posicionamento', $resultado['origem']);
        $this->assertNull($resultado['score']);
    }

    public function test_fallback_premium_retorna_margem_conservadora(): void
    {
        $resultado = $this->service->calcular(null, null, 'premium');

        $this->assertEquals(0.15, $resultado['margem']);
        $this->assertEquals('fallback_posicionamento', $resultado['origem']);
        $this->assertNull($resultado['score']);
    }

    public function test_fallback_posicionamento_desconhecido_usa_intermediaria(): void
    {
        // Guardrail defensivo: posicionamento fora do enum esperado não deve
        // quebrar o cálculo, nem produzir a margem mais agressiva por acaso.
        $resultado = $this->service->calcular(null, null, 'inexistente');

        $this->assertEquals(0.12, $resultado['margem']);
        $this->assertEquals('fallback_posicionamento', $resultado['origem']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. SCORE — OS DOIS SINAIS PRESENTES (espelha os fixtures do comando
    //    de teste de variância)
    // ─────────────────────────────────────────────────────────────────────

    public function test_cenario_longe_borda_sinais_concordantes_agressivo(): void
    {
        // razao_margem = 0.30 (bem abaixo de 0.70 → 0 pts × peso 2 = 0)
        // razao_giro   = 3.0  (bem acima de 1.5 → 0 pts × peso 1 = 0)
        // score = 0 → margem agressiva (10%)
        // No teste com a IA (Condição B), este foi o caso "sem ambiguidade
        // de fronteira" e ainda assim não convergiu (CV 10,65%).
        $resultado = $this->service->calcular(0.30, 3.0, 'medio');

        $this->assertEquals(0.10, $resultado['margem']);
        $this->assertEquals('score', $resultado['origem']);
        $this->assertEquals(0, $resultado['score']);
    }

    public function test_cenario_borda_inferior_margem_ainda_agressivo(): void
    {
        // razao_margem = 0.69 (< 0.70 → 0 pts × 2 = 0)
        // razao_giro   = 1.0  (neutro, 0.8–1.5 → 1 pt)
        // score = 1 → margem agressiva (10%)
        $resultado = $this->service->calcular(0.69, 1.0, 'medio');

        $this->assertEquals(0.10, $resultado['margem']);
        $this->assertEquals(1, $resultado['score']);
    }

    public function test_cenario_borda_superior_margem_passa_para_intermediario(): void
    {
        // razao_margem = 0.71 (≥ 0.70 → 1 pt × 2 = 2)
        // razao_giro   = 1.0  (neutro → 1 pt)
        // score = 3 → margem intermediária (12%)
        //
        // Diferença de apenas 0,02 em razao_margem (0.69 → 0.71) muda a
        // margem de 10% para 12% de forma determinística e reprodutível —
        // exatamente o tipo de fronteira que causava hesitação/CV alto na IA.
        $resultado = $this->service->calcular(0.71, 1.0, 'medio');

        $this->assertEquals(0.12, $resultado['margem']);
        $this->assertEquals(3, $resultado['score']);
    }

    public function test_sinais_totalmente_conservadores_atingem_score_maximo(): void
    {
        // razao_margem = 0.95 (≥ 0.90 → 2 pts × 2 = 4)
        // razao_giro   = 0.5  (< 0.8 → 2 pts)
        // score = 6 → margem conservadora (15%)
        $resultado = $this->service->calcular(0.95, 0.5, 'medio');

        $this->assertEquals(0.15, $resultado['margem']);
        $this->assertEquals('score', $resultado['origem']);
        $this->assertEquals(6, $resultado['score']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. LIMIARES EXATOS — comportamento nas fronteiras (0,70 / 0,90 / 0,8 / 1,5)
    // ─────────────────────────────────────────────────────────────────────

    public function test_razao_margem_exatamente_no_limiar_inferior_conta_como_moderado(): void
    {
        // 0.70 exato cai no ramo "< 0.90" (1 pt), não no ramo "< 0.70" (0 pts)
        $resultado = $this->service->calcular(0.70, 1.0, 'medio');

        // pontosMargem = 1 × 2 = 2, pontosGiro (1.0, neutro) = 1 → score = 3
        $this->assertEquals(3, $resultado['score']);
        $this->assertEquals(0.12, $resultado['margem']);
    }

    public function test_razao_margem_exatamente_no_limiar_superior_conta_como_conservador(): void
    {
        // 0.90 exato cai no ramo "≥ 0.90" (2 pts), não no ramo "< 0.90" (1 pt)
        $resultado = $this->service->calcular(0.90, 1.0, 'medio');

        // pontosMargem = 2 × 2 = 4, pontosGiro (1.0, neutro) = 1 → score = 5
        $this->assertEquals(5, $resultado['score']);
        $this->assertEquals(0.15, $resultado['margem']);
    }

    public function test_razao_giro_exatamente_no_limiar_inferior_conta_como_neutro(): void
    {
        // 0.8 exato cai no ramo "≥ 0.8" (1 pt), não no ramo "< 0.8" (2 pts)
        $resultado = $this->service->calcular(0.50, 0.8, 'medio');

        // pontosMargem (0.50 < 0.70) = 0, pontosGiro = 1 → score = 1
        $this->assertEquals(1, $resultado['score']);
        $this->assertEquals(0.10, $resultado['margem']);
    }

    public function test_razao_giro_exatamente_no_limiar_superior_conta_como_neutro(): void
    {
        // 1.5 exato cai no ramo "0.8–1.5" (1 pt), não no ramo "> 1.5" (0 pts)
        $resultado = $this->service->calcular(0.95, 1.5, 'medio');

        // pontosMargem (0.95 ≥ 0.90) = 2 × 2 = 4, pontosGiro = 1 → score = 5
        $this->assertEquals(5, $resultado['score']);
        $this->assertEquals(0.15, $resultado['margem']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. APENAS UM SINAL PRESENTE — normalização para a escala 0–6
    // ─────────────────────────────────────────────────────────────────────

    public function test_apenas_razao_margem_presente_agressiva_normaliza_para_score_zero(): void
    {
        // pontosMargem = 0 × 2 = 0 → normalizado: round((0/4)*6) = 0
        $resultado = $this->service->calcular(0.30, null, 'medio');

        $this->assertEquals('score', $resultado['origem']);
        $this->assertEquals(0, $resultado['score']);
        $this->assertEquals(0.10, $resultado['margem']);
    }

    public function test_apenas_razao_margem_presente_conservadora_normaliza_para_score_maximo(): void
    {
        // pontosMargem = 2 × 2 = 4 → normalizado: round((4/4)*6) = 6
        $resultado = $this->service->calcular(0.95, null, 'medio');

        $this->assertEquals(6, $resultado['score']);
        $this->assertEquals(0.15, $resultado['margem']);
    }

    public function test_apenas_razao_giro_presente_agressivo_normaliza_para_score_zero(): void
    {
        // pontosGiro = 0 → normalizado: round((0/2)*6) = 0
        $resultado = $this->service->calcular(null, 3.0, 'medio');

        $this->assertEquals('score', $resultado['origem']);
        $this->assertEquals(0, $resultado['score']);
        $this->assertEquals(0.10, $resultado['margem']);
    }

    public function test_apenas_razao_giro_presente_conservador_normaliza_para_score_maximo(): void
    {
        // pontosGiro = 2 → normalizado: round((2/2)*6) = 6
        $resultado = $this->service->calcular(null, 0.5, 'medio');

        $this->assertEquals(6, $resultado['score']);
        $this->assertEquals(0.15, $resultado['margem']);
    }

    public function test_apenas_razao_giro_presente_neutro_normaliza_para_score_intermediario(): void
    {
        // pontosGiro = 1 → normalizado: round((1/2)*6) = 3
        $resultado = $this->service->calcular(null, 1.0, 'medio');

        $this->assertEquals(3, $resultado['score']);
        $this->assertEquals(0.12, $resultado['margem']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. MAPEAMENTO SCORE → MARGEM (faixas 0–1 / 2–4 / 5–6)
    // ─────────────────────────────────────────────────────────────────────

    #[DataProvider('scoresEMargensEsperadas')]
    public function test_mapeamento_score_para_margem(float $razaoMargem, float $razaoGiro, int $scoreEsperado, float $margemEsperada): void
    {
        $resultado = $this->service->calcular($razaoMargem, $razaoGiro, 'medio');

        $this->assertEquals($scoreEsperado, $resultado['score']);
        $this->assertEquals($margemEsperada, $resultado['margem']);
    }

    public static function scoresEMargensEsperadas(): array
    {
        return [
            // razao_margem, razao_giro, score esperado, margem esperada
            'score 0 (agressivo)'        => [0.30, 3.0, 0, 0.10],
            'score 1 (ainda agressivo)'  => [0.69, 1.0, 1, 0.10],
            'score 2 (intermediario)'    => [0.71, 3.0, 2, 0.12],
            'score 4 (ainda intermed.)'  => [0.71, 0.5, 4, 0.12],
            'score 5 (conservador)'      => [0.90, 1.0, 5, 0.15],
            'score 6 (bem conservador)'  => [0.95, 0.5, 6, 0.15],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6. PUREZA — mesma entrada sempre produz a mesma saída (é isso, na
    //    prática, que resolve o problema de CV descrito no relatório)
    // ─────────────────────────────────────────────────────────────────────

    public function test_mesma_entrada_produz_sempre_o_mesmo_resultado(): void
    {
        $resultados = [];
        for ($i = 0; $i < 20; $i++) {
            $resultados[] = $this->service->calcular(0.30, 3.0, 'medio');
        }

        $margens = array_unique(array_column($resultados, 'margem'));
        $scores  = array_unique(array_column($resultados, 'score'));

        // CV = 0% por construção: um único valor distinto em 20 execuções
        $this->assertCount(1, $margens);
        $this->assertCount(1, $scores);
    }
}