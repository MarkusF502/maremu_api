<?php

namespace Tests\Unit;

use App\Services\OnboardingService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OnboardingServiceTest extends TestCase
{
    private OnboardingService $service;

    protected function setUp(): void
    {
        $this->service = new OnboardingService();
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. ESTRUTURA DO RETORNO
    // ─────────────────────────────────────────────────────────────────────

    public function test_retorna_estrutura_completa(): void
    {
        $resultado = $this->service->inferirDadosDaLoja(
            faixaFaturamento: 'de_10k_a_30k',
            posicionamento:   'medio',
            regime:           'simples_nacional',
            canais:           ['loja_fisica']
        );

        $this->assertArrayHasKey('loja', $resultado);
        $this->assertArrayHasKey('canais', $resultado);
        $this->assertArrayHasKey('resumo', $resultado);
    }

    public function test_loja_tem_todos_os_campos_necessarios(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', ['loja_fisica']);

        $camposEsperados = [
            'faturamento_medio_mensal',
            'custo_fixo_mensal',
            'custo_fixo_origem',
            'volume_vendas_esperado',
            'margem_lucro_desejada',
            'posicionamento',
            'regime_tributario',
            'aliquota_efetiva',
            'aliquota_origem',
        ];

        foreach ($camposEsperados as $campo) {
            $this->assertArrayHasKey($campo, $resultado['loja'], "Campo ausente: {$campo}");
        }
    }

    public function test_canal_tem_todos_os_campos_necessarios(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', ['loja_fisica', 'marketplace']);

        $this->assertCount(2, $resultado['canais']);

        foreach ($resultado['canais'] as $canal) {
            $this->assertArrayHasKey('canal', $canal);
            $this->assertArrayHasKey('taxa_percentual', $canal);
            $this->assertArrayHasKey('taxa_origem', $canal);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. INFERÊNCIA DE FAIXA DE FATURAMENTO
    // ─────────────────────────────────────────────────────────────────────

    public function test_faixa_ate_10k_gera_custo_fixo_menor(): void
    {
        $pequena = $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', ['loja_fisica']);
        $grande  = $this->service->inferirDadosDaLoja('acima_80k', 'popular', 'simples_nacional', ['loja_fisica']);

        $this->assertLessThan(
            $grande['loja']['custo_fixo_mensal'],
            $pequena['loja']['custo_fixo_mensal']
        );
    }

    public function test_faixa_maior_gera_volume_de_vendas_maior(): void
    {
        $pequena = $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', ['loja_fisica']);
        $grande  = $this->service->inferirDadosDaLoja('acima_80k', 'popular', 'simples_nacional', ['loja_fisica']);

        $this->assertLessThan(
            $grande['loja']['volume_vendas_esperado'],
            $pequena['loja']['volume_vendas_esperado']
        );
    }

    public function test_faturamento_medio_e_ponto_medio_da_faixa(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('de_10k_a_30k', 'medio', 'simples_nacional', ['loja_fisica']);

        // ponto médio de de_10k_a_30k = R$ 20.000
        $this->assertEquals(20_000.00, $resultado['loja']['faturamento_medio_mensal']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. INFERÊNCIA DE POSICIONAMENTO → MARGEM
    // ─────────────────────────────────────────────────────────────────────

    public function test_posicionamento_popular_tem_margem_menor_que_premium(): void
    {
        $popular = $this->service->inferirDadosDaLoja('de_10k_a_30k', 'popular', 'simples_nacional', ['loja_fisica']);
        $premium = $this->service->inferirDadosDaLoja('de_10k_a_30k', 'premium', 'simples_nacional', ['loja_fisica']);

        $this->assertLessThan(
            $premium['loja']['margem_lucro_desejada'],
            $popular['loja']['margem_lucro_desejada']
        );
    }

    public function test_posicionamento_e_preservado_no_retorno(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('de_10k_a_30k', 'premium', 'simples_nacional', ['loja_fisica']);
        $this->assertEquals('premium', $resultado['loja']['posicionamento']);
    }

    public function test_margem_popular_e_25_por_cento(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', ['loja_fisica']);
        $this->assertEquals(0.25, $resultado['loja']['margem_lucro_desejada']);
    }

    public function test_margem_premium_e_50_por_cento(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('acima_80k', 'premium', 'simples_nacional', ['loja_fisica']);
        $this->assertEquals(0.50, $resultado['loja']['margem_lucro_desejada']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 4. INFERÊNCIA DE REGIME → ALÍQUOTA
    // ─────────────────────────────────────────────────────────────────────

    public function test_simples_nacional_tem_aliquota_6_por_cento(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', ['loja_fisica']);
        $this->assertEquals(0.06, $resultado['loja']['aliquota_efetiva']);
    }

    public function test_lucro_real_tem_aliquota_maior_que_simples(): void
    {
        $simples = $this->service->inferirDadosDaLoja('de_30k_a_80k', 'medio', 'simples_nacional', ['loja_fisica']);
        $real    = $this->service->inferirDadosDaLoja('de_30k_a_80k', 'medio', 'lucro_real', ['loja_fisica']);

        $this->assertGreaterThan(
            $simples['loja']['aliquota_efetiva'],
            $real['loja']['aliquota_efetiva']
        );
    }

    public function test_regime_tributario_e_preservado_no_retorno(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'lucro_presumido', ['loja_fisica']);
        $this->assertEquals('lucro_presumido', $resultado['loja']['regime_tributario']);
    }

    public function test_aviso_contador_falso_para_simples_nacional(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', ['loja_fisica']);
        $this->assertFalse($resultado['resumo']['aviso_contador']);
    }

    public function test_aviso_contador_verdadeiro_para_lucro_presumido_e_real(): void
    {
        $presumido = $this->service->inferirDadosDaLoja('de_30k_a_80k', 'medio', 'lucro_presumido', ['loja_fisica']);
        $real      = $this->service->inferirDadosDaLoja('de_30k_a_80k', 'medio', 'lucro_real', ['loja_fisica']);

        $this->assertTrue($presumido['resumo']['aviso_contador']);
        $this->assertTrue($real['resumo']['aviso_contador']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 5. INFERÊNCIA DE CANAIS
    // ─────────────────────────────────────────────────────────────────────

    public function test_marketplace_tem_taxa_maior_que_loja_fisica(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('de_10k_a_30k', 'medio', 'simples_nacional', ['loja_fisica', 'marketplace']);

        $taxaFisica      = collect($resultado['canais'])->firstWhere('canal', 'loja_fisica')['taxa_percentual'];
        $taxaMarketplace = collect($resultado['canais'])->firstWhere('canal', 'marketplace')['taxa_percentual'];

        $this->assertGreaterThan($taxaFisica, $taxaMarketplace);
    }

    public function test_multiplos_canais_geram_multiplos_registros(): void
    {
        $resultado = $this->service->inferirDadosDaLoja(
            'de_10k_a_30k', 'medio', 'simples_nacional',
            ['loja_fisica', 'marketplace', 'instagram_whatsapp']
        );

        $this->assertCount(3, $resultado['canais']);
    }

    public function test_todos_os_canais_tem_origem_estimado_pelo_sistema(): void
    {
        $resultado = $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', ['loja_fisica', 'marketplace']);

        foreach ($resultado['canais'] as $canal) {
            $this->assertEquals('estimado_pelo_sistema', $canal['taxa_origem']);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // 6. TAXA CANAL CONSERVADORA
    // ─────────────────────────────────────────────────────────────────────

    public function test_taxa_conservadora_retorna_a_maior_taxa(): void
    {
        // marketplace = 15%, loja_fisica = 3%
        $taxa = $this->service->taxaCanalConservadora(['loja_fisica', 'marketplace']);
        $this->assertEquals(0.15, $taxa);
    }

    public function test_taxa_conservadora_com_canal_unico(): void
    {
        $taxa = $this->service->taxaCanalConservadora(['loja_fisica']);
        $this->assertEquals(0.03, $taxa);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 7. VALIDAÇÃO DE ENTRADAS INVÁLIDAS
    // ─────────────────────────────────────────────────────────────────────

    public function test_lanca_excecao_para_faixa_invalida(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->inferirDadosDaLoja('valor_invalido', 'popular', 'simples_nacional', ['loja_fisica']);
    }

    public function test_lanca_excecao_para_posicionamento_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->inferirDadosDaLoja('ate_10k', 'luxo', 'simples_nacional', ['loja_fisica']);
    }

    public function test_lanca_excecao_para_regime_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'mei', ['loja_fisica']);
    }

    public function test_lanca_excecao_para_canal_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', ['tiktok_shop']);
    }

    public function test_lanca_excecao_para_canais_vazios(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->inferirDadosDaLoja('ate_10k', 'popular', 'simples_nacional', []);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 8. INTEGRAÇÃO COM PricingEngine
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Garante que os valores gerados pelo OnboardingService são compatíveis
     * com os tipos esperados pelo PricingEngine — sem erros de tipo ou domínio.
     *
     * Usa produto com custo base R$ 160,00 para refletir a realidade:
     * com custo fixo de R$ 4.500/mês e 120 unidades/mês, o rateio unitário
     * é R$ 37,50 — representando 23% de um produto de R$ 160,00, viável;
     * mas 75% de um produto de R$ 50,00, matematicamente impossível.
     * Isso não é limitação da fórmula — é a realidade financeira: vender
     * um produto barato em marketplace com custo fixo alto não é sustentável.
     */
    public function test_dados_inferidos_sao_compativeis_com_pricing_engine(): void
    {
        $onboarding = new OnboardingService();
        $engine     = new \App\Services\PricingEngine();

        $dados = $onboarding->inferirDadosDaLoja('de_10k_a_30k', 'medio', 'simples_nacional', ['loja_fisica', 'marketplace']);
        $loja  = $dados['loja'];
        $taxa  = $onboarding->taxaCanalConservadora(['loja_fisica', 'marketplace']);

        // custoBase = 160,00 → custoFixoProporcional = 37,50/160 = 23%
        // somaDespesas = 6% + 15% + 23% + 35% = 79% → divisor = 0.21 (viável)
        $resultado = $engine->calcularPreco(
            custoAquisicao:        150.00,
            freteEntradaUnitario:  10.00,
            aliquotaEfetiva:       $loja['aliquota_efetiva'],
            taxaCanal:             $taxa,
            custoFixoMensal:       $loja['custo_fixo_mensal'],
            volumeVendasEsperado:  $loja['volume_vendas_esperado'],
            margemLucroDesejada:   $loja['margem_lucro_desejada']
        );

        $this->assertGreaterThan(0, $resultado['preco_piso']);
        $this->assertGreaterThan($resultado['preco_piso'], $resultado['preco_venda']);
    }
}