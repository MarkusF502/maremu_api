<?php

namespace Tests\Unit;

use App\Services\OnboardingTermosService;
use PHPUnit\Framework\TestCase;

class OnboardingTermosServiceTest extends TestCase
{
    private OnboardingTermosService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OnboardingTermosService();
    }

    // ── Normalização / citação (SPEC §5) ────────────────────────────────

    public function test_citacao_valida_ignora_acentuacao_e_pontuacao(): void
    {
        $texto = 'Pago 2500 de ALUGUEL, mais as contas.';
        $this->assertTrue($this->service->citacaoValida('pago 2500 de aluguel', $texto));
    }

    public function test_citacao_invalida_quando_nao_e_substring(): void
    {
        $texto = 'Tenho uma loja pequena no centro da cidade.';
        $this->assertFalse($this->service->citacaoValida('pago 2500 de aluguel', $texto));
    }

    public function test_citacao_nula_e_invalida(): void
    {
        $this->assertFalse($this->service->citacaoValida(null, 'qualquer texto'));
    }

    // ── Roteamento / verificação de citação (SPEC §5.3 e §6.1) ──────────

    public function test_termo_explicito_com_citacao_falsa_e_rebaixado_a_ausente(): void
    {
        $texto = 'Tenho uma loja de roupas em Campinas.';
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'pago 2500 de aluguel'],
                'n_funcionarios' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
            ],
            termosFaturamentoIa: [],
            textoOriginal: $texto,
            regimeTributario: 'simples_nacional',
        );

        $this->assertSame('ausente', $estado['custo_fixo_mensal']['custos_do_local']['origem']);
        $this->assertSame('pendente_escolha', $estado['custo_fixo_mensal']['custos_do_local']['status']);
    }

    public function test_explicito_zero_sem_valor_e_normalizado_para_zero(): void
    {
        // Regressão: a IA às vezes retorna origem='explicito_zero' com
        // valor=null (leu "zero" como "nada a reportar", não como o número
        // 0) — isso não pode travar o cálculo final num null silencioso.
        // Cobre tanto custos_do_local (ex: "a loja é minha, não pago
        // aluguel") quanto outros_custos_fixos (ex: "fora isso não tenho
        // nenhum outro custo fixo").
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => null, 'origem' => 'explicito_zero', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => null, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
            termosVolumeVendasIa: ['volume_vendas_direto' => ['valor' => 100, 'origem' => 'explicito', 'citacao' => 'x']],
            termosMargemLucroIa: ['margem_direta' => ['valor' => 25, 'origem' => 'explicito', 'citacao' => 'x']],
        );

        $this->assertSame(0, $estado['custo_fixo_mensal']['custos_do_local']['valor']);
        $this->assertSame(0, $estado['custo_fixo_mensal']['outros_custos_fixos']['valor']);
        $this->assertSame('aceito', $estado['custo_fixo_mensal']['custos_do_local']['status']);
        $this->assertSame('aceito', $estado['custo_fixo_mensal']['outros_custos_fixos']['status']);

        $roteamento = $this->service->gerarPendencias($estado);
        $this->assertSame([], $roteamento['pendencias']);
        $this->assertEquals(0.0, $this->service->calcularCustoFixo($estado['custo_fixo_mensal']));
    }

    public function test_n_funcionarios_ausente_nunca_e_assumido_como_zero(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'pago 2500'],
                'n_funcionarios' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'pago 2500'],
            ],
            termosFaturamentoIa: [],
            textoOriginal: 'pago 2500',
            regimeTributario: 'simples_nacional',
        );

        $this->assertSame('pendente_escolha', $estado['custo_fixo_mensal']['n_funcionarios']['status']);
        $this->assertNull($estado['custo_fixo_mensal']['n_funcionarios']['valor']);
    }

    public function test_custo_total_por_funcionario_usa_tabela_por_regime_como_sugestao(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 2, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: [],
            textoOriginal: 'x',
            regimeTributario: 'lucro_presumido',
        );

        $termo = $estado['custo_fixo_mensal']['custo_total_por_funcionario'];
        $this->assertSame('pendente_confirmacao', $termo['status']);
        $this->assertEquals(2698.04, $termo['valor_sugerido']);
    }

    // ── Ordem de pendências / dependência lógica (SPEC §6.2) ────────────

    public function test_custo_total_por_funcionario_so_entra_se_ha_funcionarios(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 5000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
        );

        $roteamento = $this->service->gerarPendencias($estado);

        $ids = array_column($roteamento['pendencias'], 'id');
        $this->assertNotContains('custo_total_por_funcionario', $ids);
        $this->assertTrue($roteamento['grupoAResolvido']);
    }

    public function test_grupo_b_so_e_processado_apos_grupo_a_resolvido(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'n_funcionarios' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null]],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
        );

        $roteamento = $this->service->gerarPendencias($estado);
        $ids = array_column($roteamento['pendencias'], 'id');

        $this->assertSame('custos_do_local', $ids[0]);
        $this->assertNotContains('faturamento_direto', $ids);
    }

    public function test_checagem_cruzada_gerada_quando_custo_maior_que_faturamento(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 6000, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 5000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
            // Rota 1 do faturamento — volume/margem precisam vir resolvidos
            // para que só a checagem cruzada (Grupo C) sobre no resultado.
            termosVolumeVendasIa: ['volume_vendas_direto' => ['valor' => 100, 'origem' => 'explicito', 'citacao' => 'x']],
            termosMargemLucroIa: ['margem_direta' => ['valor' => 25, 'origem' => 'explicito', 'citacao' => 'x']],
        );

        $roteamento = $this->service->gerarPendencias($estado);

        $this->assertCount(1, $roteamento['pendencias']);
        $this->assertSame('checagem_cruzada_custo_faturamento', $roteamento['pendencias'][0]['id']);
        $this->assertSame('confirmacao_agregada', $roteamento['pendencias'][0]['tipo']);
    }

    public function test_sem_pendencias_quando_tudo_explicito_e_coerente(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
            // Faturamento é Rota 1 (faturamento_direto) aqui, então volume_vendas_esperado
            // e margem_lucro_desejada não têm de onde ser derivados — precisam vir
            // explícitos também para que Grupo D não gere pendência.
            termosVolumeVendasIa: ['volume_vendas_direto' => ['valor' => 200, 'origem' => 'explicito', 'citacao' => 'x']],
            termosMargemLucroIa: ['margem_direta' => ['valor' => 25, 'origem' => 'explicito', 'citacao' => 'x']],
        );

        $roteamento = $this->service->gerarPendencias($estado);
        $this->assertSame([], $roteamento['pendencias']);

        $this->assertEquals(2500.0, $this->service->calcularCustoFixo($estado['custo_fixo_mensal']));
        $this->assertEquals(12000.0, $this->service->calcularFaturamento($estado['faturamento_medio_mensal']));
        $this->assertEquals(200, $this->service->calcularVolumeVendas($estado['volume_vendas_esperado']));
        $this->assertEquals(0.25, $this->service->calcularMargemLucro($estado['margem_lucro_desejada']));
    }

    // ── Mesclagem de respostas do wizard (usada por responder-pendencias) ─

    public function test_mesclar_resposta_binaria_resolve_n_funcionarios(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
        );

        $estado = $this->service->mesclarRespostas($estado, [
            ['id' => 'n_funcionarios', 'resposta' => 'sim'],
        ]);

        $roteamento = $this->service->gerarPendencias($estado);
        $ids = array_column($roteamento['pendencias'], 'id');

        $this->assertSame('aceito', $estado['custo_fixo_mensal']['n_funcionarios']['status']);
        $this->assertContains('custo_total_por_funcionario', $ids);
    }

    public function test_mesclar_confirmacao_de_custo_por_funcionario_conclui_grupo_a(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 1, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
            termosVolumeVendasIa: ['volume_vendas_direto' => ['valor' => 100, 'origem' => 'explicito', 'citacao' => 'x']],
            termosMargemLucroIa: ['margem_direta' => ['valor' => 25, 'origem' => 'explicito', 'citacao' => 'x']],
        );

        $estado = $this->service->mesclarRespostas($estado, [
            ['id' => 'custo_total_por_funcionario', 'resposta' => 'confirmado'],
        ]);

        $roteamento = $this->service->gerarPendencias($estado);
        $this->assertSame([], $roteamento['pendencias']);
        $this->assertEquals(2500 + 2280.50, $this->service->calcularCustoFixo($estado['custo_fixo_mensal']));
    }

    public function test_rejeitar_custo_sugerido_por_funcionario_pergunta_o_valor_real_em_vez_de_aceitar(): void
    {
        // Regressão: "Não, é mais" / "Não, é menos" não pode se comportar
        // como "Sim, está certo" — precisa perguntar o valor real, não
        // aceitar a sugestão da tabela do regime tributário de qualquer jeito.
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 2, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
        );

        $estado = $this->service->mesclarRespostas($estado, [
            ['id' => 'custo_total_por_funcionario', 'resposta' => 'ajustar'],
        ]);

        $this->assertSame('pendente_ajuste', $estado['custo_fixo_mensal']['custo_total_por_funcionario']['status']);
        $this->assertNull($estado['custo_fixo_mensal']['custo_total_por_funcionario']['valor']);

        $roteamento = $this->service->gerarPendencias($estado);
        $this->assertCount(1, $roteamento['pendencias']);
        $this->assertSame('custo_total_por_funcionario', $roteamento['pendencias'][0]['id']);
        $this->assertSame('confirmacao_valor_faixa', $roteamento['pendencias'][0]['tipo']);

        // Respondendo o valor real, agora sim fica aceito com o valor informado.
        $estado = $this->service->mesclarRespostas($estado, [
            ['id' => 'custo_total_por_funcionario', 'resposta' => 3200],
        ]);

        $this->assertSame('aceito', $estado['custo_fixo_mensal']['custo_total_por_funcionario']['status']);
        $this->assertSame('respondido_pelo_lojista', $estado['custo_fixo_mensal']['custo_total_por_funcionario']['origem']);
        $this->assertEquals(2500 + 2 * 3200, $this->service->calcularCustoFixo($estado['custo_fixo_mensal']));
    }

    // ── volume_vendas_esperado: derivado da Rota 2 ou termo próprio (Rota 1) ─

    public function test_volume_vendas_e_derivado_da_quantidade_vendida_na_rota_decomposicao(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: [
                'faturamento_direto' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'quantidade_vendida' => ['valor' => 200, 'origem' => 'explicito', 'citacao' => 'x'],
                'ticket_medio' => ['valor' => 30, 'origem' => 'explicito', 'citacao' => 'x'],
                'periodicidade_informada' => 'mes',
            ],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
            // Nenhum termos_volume_vendas vindo da IA — não deveria importar,
            // a Rota 2 do faturamento já resolve o dado sozinha (SPEC §1.2).
        );

        $this->assertSame('derivado_de_faturamento', $estado['volume_vendas_esperado']['rota']);
        $this->assertSame('aceito', $estado['volume_vendas_esperado']['volume_vendas_direto']['status']);
        $this->assertSame('derivado_de_faturamento', $estado['volume_vendas_esperado']['volume_vendas_direto']['origem']);
        $this->assertSame(200, $this->service->calcularVolumeVendas($estado['volume_vendas_esperado']));
        $this->assertEquals(6000.0, $this->service->calcularFaturamento($estado['faturamento_medio_mensal']));
    }

    public function test_volume_vendas_direto_e_termo_verificado_na_rota_faturamento_direto(): void
    {
        $texto = 'Vendo cerca de 80 peças por mês, faturamento de 12000.';
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'faturamento de 12000']],
            textoOriginal: $texto,
            regimeTributario: 'simples_nacional',
            termosVolumeVendasIa: ['volume_vendas_direto' => ['valor' => 80, 'origem' => 'explicito', 'citacao' => '80 peças por mês']],
        );

        $this->assertSame('direto', $estado['volume_vendas_esperado']['rota']);
        $this->assertSame('aceito', $estado['volume_vendas_esperado']['volume_vendas_direto']['status']);
        $this->assertSame('explicito', $estado['volume_vendas_esperado']['volume_vendas_direto']['origem']);
        $this->assertSame(80, $this->service->calcularVolumeVendas($estado['volume_vendas_esperado']));
    }

    public function test_volume_vendas_direto_fica_pendente_escolha_quando_ausente_na_rota_direta(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
        );

        $this->assertSame('direto', $estado['volume_vendas_esperado']['rota']);
        $this->assertSame('pendente_escolha', $estado['volume_vendas_esperado']['volume_vendas_direto']['status']);
        $this->assertNull($this->service->calcularVolumeVendas($estado['volume_vendas_esperado']));
    }

    // ── margem_lucro_desejada: rota direta ou decomposição por preços ───

    public function test_margem_direta_com_citacao_valida_e_aceita(): void
    {
        $texto = 'Quero ter uns 30% de margem nas vendas.';
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: $texto,
            regimeTributario: 'simples_nacional',
            termosMargemLucroIa: ['margem_direta' => ['valor' => 30, 'origem' => 'explicito', 'citacao' => '30% de margem']],
        );

        $this->assertSame('direta', $estado['margem_lucro_desejada']['rota']);
        $this->assertSame('aceito', $estado['margem_lucro_desejada']['margem_direta']['status']);
        $this->assertEquals(0.30, $this->service->calcularMargemLucro($estado['margem_lucro_desejada']));
    }

    public function test_margem_por_decomposicao_de_precos_e_calculada_sobre_a_venda(): void
    {
        $texto = 'Compro por 50 e revendo por 80.';
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: $texto,
            regimeTributario: 'simples_nacional',
            termosMargemLucroIa: [
                'margem_direta' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'preco_custo' => ['valor' => 50, 'origem' => 'explicito', 'citacao' => 'Compro por 50'],
                'preco_venda' => ['valor' => 80, 'origem' => 'explicito', 'citacao' => 'revendo por 80'],
            ],
        );

        $this->assertSame('decomposicao_precos', $estado['margem_lucro_desejada']['rota']);
        $this->assertSame('aceito', $estado['margem_lucro_desejada']['preco_custo']['status']);
        $this->assertSame('aceito', $estado['margem_lucro_desejada']['preco_venda']['status']);
        $this->assertEqualsWithDelta(0.375, $this->service->calcularMargemLucro($estado['margem_lucro_desejada']), 0.0001);
    }

    public function test_margem_ausente_em_ambas_as_rotas_fica_pendente_escolha(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
        );

        $this->assertSame('decomposicao_precos', $estado['margem_lucro_desejada']['rota']);
        $this->assertSame('pendente_escolha', $estado['margem_lucro_desejada']['preco_custo']['status']);
        $this->assertSame('pendente_escolha', $estado['margem_lucro_desejada']['preco_venda']['status']);
        $this->assertNull($this->service->calcularMargemLucro($estado['margem_lucro_desejada']));
    }

    // ── calcularVolumeVendas() / calcularMargemLucro() isolados ─────────

    public function test_calcular_volume_vendas_le_o_termo_volume_vendas_direto(): void
    {
        $this->assertSame(50, $this->service->calcularVolumeVendas(['volume_vendas_direto' => ['valor' => 50]]));
        $this->assertNull($this->service->calcularVolumeVendas(['volume_vendas_direto' => ['valor' => null]]));
        $this->assertNull($this->service->calcularVolumeVendas([]));
    }

    public function test_calcular_margem_lucro_rota_direta_divide_por_100(): void
    {
        $this->assertEquals(0.4, $this->service->calcularMargemLucro(['rota' => 'direta', 'margem_direta' => ['valor' => 40]]));
    }

    public function test_calcular_margem_lucro_rota_decomposicao_precos(): void
    {
        $this->assertEquals(0.4, $this->service->calcularMargemLucro([
            'rota' => 'decomposicao_precos',
            'preco_custo' => ['valor' => 60],
            'preco_venda' => ['valor' => 100],
        ]));
    }

    public function test_calcular_margem_lucro_e_null_quando_preco_venda_zero_ou_ausente(): void
    {
        $this->assertNull($this->service->calcularMargemLucro([
            'rota' => 'decomposicao_precos',
            'preco_custo' => ['valor' => 60],
            'preco_venda' => ['valor' => 0],
        ]));
        $this->assertNull($this->service->calcularMargemLucro([
            'rota' => 'decomposicao_precos',
            'preco_custo' => ['valor' => 60],
            'preco_venda' => ['valor' => null],
        ]));
    }

    // ── gerarPendencias() Grupo D (volume Rota 1 / margem combinada) ────

    public function test_grupo_d_gera_pendencia_de_volume_apenas_na_rota_direto_do_faturamento(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
            termosMargemLucroIa: ['margem_direta' => ['valor' => 25, 'origem' => 'explicito', 'citacao' => 'x']],
        );

        $roteamento = $this->service->gerarPendencias($estado);
        $ids = array_column($roteamento['pendencias'], 'id');

        $this->assertContains('volume_vendas_direto', $ids);
    }

    public function test_grupo_d_gera_uma_unica_pendencia_combinada_de_margem_quando_ambas_rotas_vazias(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: [
                'faturamento_direto' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'quantidade_vendida' => ['valor' => 200, 'origem' => 'explicito', 'citacao' => 'x'],
                'ticket_medio' => ['valor' => 30, 'origem' => 'explicito', 'citacao' => 'x'],
                'periodicidade_informada' => 'mes',
            ],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
        );

        $roteamento = $this->service->gerarPendencias($estado);
        $ids = array_column($roteamento['pendencias'], 'id');

        // Volume já veio derivado da Rota 2 do faturamento — não gera pendência.
        $this->assertNotContains('volume_vendas_direto', $ids);
        // Margem não resolvida em nenhuma rota — uma única pendência combinada,
        // nunca preco_custo/preco_venda separados.
        $this->assertSame(['margem_lucro_desejada'], $ids);
        $this->assertSame('confirmacao_valor_faixa', $roteamento['pendencias'][0]['tipo']);
    }

    public function test_grupo_d_nao_gera_pendencia_quando_volume_e_margem_ja_resolvidos(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: [
                'faturamento_direto' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'quantidade_vendida' => ['valor' => 200, 'origem' => 'explicito', 'citacao' => 'x'],
                'ticket_medio' => ['valor' => 30, 'origem' => 'explicito', 'citacao' => 'x'],
                'periodicidade_informada' => 'mes',
            ],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
            termosMargemLucroIa: ['margem_direta' => ['valor' => 25, 'origem' => 'explicito', 'citacao' => 'x']],
        );

        $roteamento = $this->service->gerarPendencias($estado);
        $this->assertSame([], $roteamento['pendencias']);
    }

    // ── mesclarRespostas() para os ids novos ─────────────────────────────

    public function test_mesclar_resposta_de_volume_vendas_direto(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
        );

        $estado = $this->service->mesclarRespostas($estado, [
            ['id' => 'volume_vendas_direto', 'resposta' => '150'],
        ]);

        $this->assertSame('aceito', $estado['volume_vendas_esperado']['volume_vendas_direto']['status']);
        $this->assertSame('respondido_pelo_lojista', $estado['volume_vendas_esperado']['volume_vendas_direto']['origem']);
        $this->assertSame(150, $estado['volume_vendas_esperado']['volume_vendas_direto']['valor']);
        $this->assertSame(150, $this->service->calcularVolumeVendas($estado['volume_vendas_esperado']));
    }

    public function test_mesclar_resposta_combinada_de_margem_lucro_desejada(): void
    {
        $estado = $this->service->construirEstadoInicial(
            termosCustoFixoIa: [
                'custos_do_local' => ['valor' => 2500, 'origem' => 'explicito', 'citacao' => 'x'],
                'n_funcionarios' => ['valor' => 0, 'origem' => 'explicito', 'citacao' => 'x'],
                'custo_total_por_funcionario' => ['valor' => null, 'origem' => 'ausente', 'citacao' => null],
                'outros_custos_fixos' => ['valor' => 0, 'origem' => 'explicito_zero', 'citacao' => 'x'],
            ],
            termosFaturamentoIa: ['faturamento_direto' => ['valor' => 12000, 'origem' => 'explicito', 'citacao' => 'x']],
            textoOriginal: 'x',
            regimeTributario: 'simples_nacional',
        );

        $estado = $this->service->mesclarRespostas($estado, [
            ['id' => 'margem_lucro_desejada', 'resposta' => 30],
        ]);

        $this->assertSame('direta', $estado['margem_lucro_desejada']['rota']);
        $this->assertSame('aceito', $estado['margem_lucro_desejada']['margem_direta']['status']);
        $this->assertSame('respondido_pelo_lojista', $estado['margem_lucro_desejada']['margem_direta']['origem']);
        // O valor bruto (30) é guardado como percentual, sem pré-divisão —
        // a divisão por 100 acontece em calcularMargemLucro().
        $this->assertSame(30.0, $estado['margem_lucro_desejada']['margem_direta']['valor']);
        $this->assertEquals(0.30, $this->service->calcularMargemLucro($estado['margem_lucro_desejada']));
    }
}
