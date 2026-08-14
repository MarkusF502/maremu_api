<?php

namespace Tests\Unit;

use App\Services\OnboardingGuardrail;
use PHPUnit\Framework\TestCase;

class OnboardingGuardrailTest extends TestCase
{
    private OnboardingGuardrail $guardrail;

    protected function setUp(): void
    {
        $this->guardrail = new OnboardingGuardrail();
    }

    // ── textoSuficiente() ───────────────────────────────────────────────

    public function test_rejeita_texto_curto(): void
    {
        $this->assertFalse($this->guardrail->textoSuficiente('loja de roupas'));
    }

    public function test_rejeita_repeticao_de_caractere(): void
    {
        $texto = str_repeat('a', 80);
        $this->assertFalse($this->guardrail->textoSuficiente($texto));
    }

    public function test_rejeita_repeticao_de_caractere_com_espacos(): void
    {
        $texto = str_repeat('aaa ', 30);
        $this->assertFalse($this->guardrail->textoSuficiente($texto));
    }

    public function test_rejeita_poucas_palavras_distintas(): void
    {
        $texto = str_repeat('loja loja loja loja loja loja loja loja loja loja loja loja ', 1);
        $this->assertFalse($this->guardrail->textoSuficiente($texto));
    }

    public function test_aceita_texto_valido(): void
    {
        $texto = 'Tenho uma loja de roupas femininas em Campinas, focada em moda '
            . 'casual com preço acessível. Vendo cerca de 80 peças por mês, '
            . 'faturando em torno de R$ 12 mil.';

        $this->assertTrue($this->guardrail->textoSuficiente($texto));
    }

    // ── clampar() ────────────────────────────────────────────────────────

    public function test_valor_dentro_do_range_nao_e_clampado(): void
    {
        $resultado = $this->guardrail->clampar('custo_fixo_mensal', 3000);

        $this->assertSame(3000, $resultado['valor']);
        $this->assertFalse($resultado['clampado']);
    }

    public function test_valor_fora_mas_clampavel_e_ajustado_ao_limite(): void
    {
        // range: 200 a 100000, amplitude 99800. Um pouco acima do teto,
        // dentro de 3x a amplitude — deve clampar pro teto.
        $resultado = $this->guardrail->clampar('custo_fixo_mensal', 105000);

        $this->assertSame(100000, $resultado['valor']);
        $this->assertTrue($resultado['clampado']);
    }

    public function test_valor_absurdamente_fora_vira_null(): void
    {
        // range: 1000 a 500000. 50 milhões está muito além de 3x a amplitude.
        $resultado = $this->guardrail->clampar('faturamento_medio_mensal', 50_000_000);

        $this->assertNull($resultado['valor']);
        $this->assertFalse($resultado['clampado']);
    }

    public function test_valor_abaixo_do_minimo_clampa_para_o_piso(): void
    {
        $resultado = $this->guardrail->clampar('volume_vendas_esperado', 2);

        $this->assertSame(10, $resultado['valor']);
        $this->assertTrue($resultado['clampado']);
    }

    public function test_campo_invalido_lanca_excecao(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->guardrail->clampar('campo_inexistente', 100);
    }
}
