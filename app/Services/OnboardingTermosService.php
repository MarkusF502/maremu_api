<?php

namespace App\Services;

/**
 * OnboardingTermosService
 *
 * Implementa a Spec-Extracao-Assertiva-Onboarding-Maremu: a partir desta
 * spec, `custo_fixo_mensal` e `faturamento_medio_mensal` deixam de ser
 * números finais estimados pela IA e passam a ser calculados
 * deterministicamente pelo backend a partir de termos componentes
 * (com citação literal do texto do lojista).
 *
 * Função pura (sem I/O, sem dependência de banco) — usada tanto por
 * `analisar-texto` (primeira passada, a partir da resposta da IA) quanto por
 * `responder-pendencias` (mescla as respostas do wizard e recalcula). Nenhum
 * dos dois fluxos duplica esta lógica (SPEC §8.2).
 */
class OnboardingTermosService
{
    /**
     * Custo total (salário + encargos) de um funcionário no salário mínimo,
     * por regime tributário. Mesmo padrão de manutenção manual já usado por
     * OnboardingService::ALIQUOTA_POR_REGIME. Valores de referência 2026.
     */
    public const CUSTO_FUNCIONARIO_SALARIO_MINIMO_POR_REGIME = [
        'simples_nacional' => 2280.50,
        'lucro_presumido'  => 2698.04,
        'lucro_real'       => 2698.04,
    ];

    /**
     * Valor de referência para dias de funcionamento por mês, usado como
     * sugestão quando o texto não informa (SPEC §6.1).
     */
    public const DIAS_FUNCIONAMENTO_MES_REFERENCIA = 26;

    // ─────────────────────────────────────────────────────────────────────
    // NORMALIZAÇÃO E VERIFICAÇÃO DE CITAÇÃO (SPEC §5)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * lowercase, remove acentuação, remove pontuação e espaços múltiplos.
     */
    public function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto);

        // remove acentuação (transliteração ASCII)
        $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT', $texto);
        if ($transliterado !== false) {
            $texto = $transliterado;
        }

        // remove tudo que não for letra/número/espaço
        $texto = preg_replace('/[^a-z0-9\s]/u', ' ', $texto) ?? $texto;

        // colapsa espaços múltiplos
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);

        return $texto;
    }

    /**
     * Verifica se a citação é um recorte literal (após normalização) do
     * texto original. Sem fuzzy matching — decisão explícita da SPEC §5.
     */
    public function citacaoValida(?string $citacao, string $textoOriginal): bool
    {
        if ($citacao === null || trim($citacao) === '') {
            return false;
        }

        $citacaoNormalizada = $this->normalizar($citacao);

        if ($citacaoNormalizada === '') {
            return false;
        }

        return str_contains($this->normalizar($textoOriginal), $citacaoNormalizada);
    }

    /**
     * Aplica a verificação de citação a um termo bruto vindo da IA
     * (SPEC §5.3): se a origem é 'explicito'/'explicito_zero' e a citação
     * não bate, o termo é rebaixado para 'ausente'.
     *
     * @param  array{valor: mixed, origem: string, citacao: ?string} $termo
     * @return array{valor: mixed, origem: string, citacao: ?string}
     */
    private function validarCitacaoDoTermo(array $termo, string $textoOriginal): array
    {
        $origem = $termo['origem'] ?? 'ausente';

        if (! in_array($origem, ['explicito', 'explicito_zero'], true)) {
            return ['valor' => null, 'origem' => 'ausente', 'citacao' => null];
        }

        if (! $this->citacaoValida($termo['citacao'] ?? null, textoOriginal: $textoOriginal)) {
            return ['valor' => null, 'origem' => 'ausente', 'citacao' => null];
        }

        return $termo;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PROCESSAMENTO PRINCIPAL
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Constrói o estado inicial dos termos a partir da resposta bruta da IA
     * (SPEC §3), já com a verificação de citação aplicada (SPEC §5) e o
     * roteamento por termo (SPEC §6.1) — mas ainda sem aplicar respostas do
     * wizard.
     *
     * @param  array  $termosCustoFixoIa   Estrutura bruta enviada pela IA para custo_fixo_mensal (SPEC §3.1)
     * @param  array  $termosFaturamentoIa Estrutura bruta enviada pela IA para faturamento_medio_mensal (SPEC §3.2)
     * @param  string $textoOriginal       Texto dissertativo do lojista
     * @param  string $regimeTributario
     *
     * @return array{custo_fixo_mensal: array, faturamento_medio_mensal: array}
     *         Cada termo: ['valor' => mixed, 'origem' => string, 'citacao' => ?string, 'status' => string, 'valor_sugerido' => ?float]
     */
    public function construirEstadoInicial(
        array $termosCustoFixoIa,
        array $termosFaturamentoIa,
        string $textoOriginal,
        string $regimeTributario,
        array $termosVolumeVendasIa = [],
        array $termosMargemLucroIa = [],
    ): array {
        $faturamento = $this->construirTermosFaturamento($termosFaturamentoIa, $textoOriginal);

        return [
            'custo_fixo_mensal'        => $this->construirTermosCustoFixo($termosCustoFixoIa, $textoOriginal, $regimeTributario),
            'faturamento_medio_mensal' => $faturamento,
            'volume_vendas_esperado'   => $this->construirTermosVolumeVendas($termosVolumeVendasIa, $textoOriginal, $faturamento),
            'margem_lucro_desejada'    => $this->construirTermosMargemLucro($termosMargemLucroIa, $textoOriginal),
        ];
    }

    private function construirTermosCustoFixo(array $bruto, string $textoOriginal, string $regimeTributario): array
    {
        $custosDoLocal = $this->validarCitacaoDoTermo($bruto['custos_do_local'] ?? [], $textoOriginal);
        $nFuncionarios = $this->validarCitacaoDoTermo($bruto['n_funcionarios'] ?? [], $textoOriginal);
        $custoPorFuncionario = $this->validarCitacaoDoTermo($bruto['custo_total_por_funcionario'] ?? [], $textoOriginal);
        $outrosCustos = $this->validarCitacaoDoTermo($bruto['outros_custos_fixos'] ?? [], $textoOriginal);

        $termos = [];

        // custos_do_local: nunca tem valor-padrão possível (SPEC §6.1) → PENDENTE_ESCOLHA
        $termos['custos_do_local'] = $this->rotear('custos_do_local', $custosDoLocal, null);

        // n_funcionarios: sem valor-padrão → PENDENTE_ESCOLHA (nunca assumido como 0 — SPEC §11)
        $termos['n_funcionarios'] = $this->rotear('n_funcionarios', $nFuncionarios, null);

        // custo_total_por_funcionario: valor-padrão vem da tabela por regime tributário (SPEC §4)
        $valorSugeridoPorFuncionario = self::CUSTO_FUNCIONARIO_SALARIO_MINIMO_POR_REGIME[$regimeTributario] ?? null;
        $termos['custo_total_por_funcionario'] = $this->rotear('custo_total_por_funcionario', $custoPorFuncionario, $valorSugeridoPorFuncionario);

        // outros_custos_fixos: sem valor-padrão → PENDENTE_ESCOLHA
        $termos['outros_custos_fixos'] = $this->rotear('outros_custos_fixos', $outrosCustos, null);

        return $termos;
    }

    private function construirTermosFaturamento(array $bruto, string $textoOriginal): array
    {
        $termos = [];

        // A resposta da IA sempre inclui a chave faturamento_direto (o
        // schema exige as duas rotas presentes) — a rota real é decidida
        // pela origem informada, não pela mera presença da chave.
        $origemFaturamentoDireto = $bruto['faturamento_direto']['origem'] ?? 'ausente';

        if (in_array($origemFaturamentoDireto, ['explicito', 'explicito_zero'], true)) {
            $faturamentoDireto = $this->validarCitacaoDoTermo(
                array_merge(['origem' => 'explicito'], $bruto['faturamento_direto']),
                $textoOriginal
            );
            $termos['rota'] = 'direto';
            $termos['faturamento_direto'] = $this->rotear('faturamento_direto', $faturamentoDireto, null);

            return $termos;
        }

        $termos['rota'] = 'decomposicao';

        $quantidadeVendida = $this->validarCitacaoDoTermo(
            array_merge(['origem' => 'explicito'], $bruto['quantidade_vendida'] ?? []),
            $textoOriginal
        );
        $termos['quantidade_vendida'] = $this->rotear('quantidade_vendida', $quantidadeVendida, null);

        $ticketMedio = $this->validarCitacaoDoTermo(
            array_merge(['origem' => 'explicito'], $bruto['ticket_medio'] ?? []),
            $textoOriginal
        );
        $termos['ticket_medio'] = $this->rotear('ticket_medio', $ticketMedio, null);

        // 'nao_informada' é o sentinela usado pela IA quando a rota é
        // "direto" (SPEC schema) — se chegamos aqui (rota decomposição) e
        // ainda assim vier ausente/sentinela, assumimos "mes" (mais comum)
        // em vez de deixar o cálculo final travado num null silencioso.
        $periodicidade = $bruto['periodicidade_informada'] ?? null;
        if ($periodicidade === null || $periodicidade === 'nao_informada') {
            $periodicidade = 'mes';
        }
        $termos['periodicidade_informada'] = $periodicidade;

        if ($periodicidade === 'dia') {
            $diasFuncionamento = $this->validarCitacaoDoTermo($bruto['dias_funcionamento_mes'] ?? [], $textoOriginal);
            $termos['dias_funcionamento_mes'] = $this->rotear(
                'dias_funcionamento_mes',
                $diasFuncionamento,
                self::DIAS_FUNCIONAMENTO_MES_REFERENCIA
            );
        }

        return $termos;
    }

    /**
     * volume_vendas_esperado: quando o faturamento usa a Rota 2
     * (decomposição), a quantidade de peças vendidas já foi extraída e
     * verificada como `quantidade_vendida` — reaproveitamos esse valor em
     * vez de pedir à IA uma segunda estimativa independente para o mesmo
     * dado. Só na Rota 1 (faturamento direto) é que volume_vendas_esperado
     * vira um termo próprio, com sua própria verificação de citação.
     */
    private function construirTermosVolumeVendas(array $bruto, string $textoOriginal, array $termosFaturamento): array
    {
        if (($termosFaturamento['rota'] ?? null) === 'decomposicao') {
            $quantidade = $termosFaturamento['quantidade_vendida']['valor'] ?? null;

            return [
                'rota' => 'derivado_de_faturamento',
                'volume_vendas_direto' => [
                    'nome'    => 'volume_vendas_direto',
                    'valor'   => $quantidade,
                    'origem'  => 'derivado_de_faturamento',
                    'citacao' => null,
                    'status'  => $quantidade !== null ? 'aceito' : 'pendente_escolha',
                ],
            ];
        }

        $volumeVendasDireto = $this->validarCitacaoDoTermo(
            array_merge(['origem' => 'explicito'], $bruto['volume_vendas_direto'] ?? []),
            $textoOriginal
        );

        return [
            'rota' => 'direto',
            'volume_vendas_direto' => $this->rotear('volume_vendas_direto', $volumeVendasDireto, null),
        ];
    }

    /**
     * margem_lucro_desejada: mesmo princípio de rota dupla do faturamento —
     * ou o texto informa a margem diretamente, ou informa preço de custo e
     * de venda para o backend calcular deterministicamente.
     */
    private function construirTermosMargemLucro(array $bruto, string $textoOriginal): array
    {
        $origemMargemDireta = $bruto['margem_direta']['origem'] ?? 'ausente';

        if ($origemMargemDireta === 'explicito') {
            $margemDireta = $this->validarCitacaoDoTermo(
                array_merge(['origem' => 'explicito'], $bruto['margem_direta']),
                $textoOriginal
            );

            return [
                'rota'          => 'direta',
                'margem_direta' => $this->rotear('margem_direta', $margemDireta, null),
            ];
        }

        $precoCusto = $this->validarCitacaoDoTermo(
            array_merge(['origem' => 'explicito'], $bruto['preco_custo'] ?? []),
            $textoOriginal
        );
        $precoVenda = $this->validarCitacaoDoTermo(
            array_merge(['origem' => 'explicito'], $bruto['preco_venda'] ?? []),
            $textoOriginal
        );

        return [
            'rota'        => 'decomposicao_precos',
            'preco_custo' => $this->rotear('preco_custo', $precoCusto, null),
            'preco_venda' => $this->rotear('preco_venda', $precoVenda, null),
        ];
    }

    /**
     * Roteamento determinístico por termo (SPEC §6.1).
     */
    private function rotear(string $nome, array $termo, ?float $valorSugerido): array
    {
        $origem = $termo['origem'] ?? 'ausente';

        if (in_array($origem, ['explicito', 'explicito_zero'], true)) {
            // 'explicito_zero' significa "o texto deixa claro que esse custo é
            // zero" (ex: "não pago aluguel", "fora isso não tenho mais nenhum
            // custo") — o valor é sempre 0 por definição. A IA às vezes retorna
            // valor=null nesse caso (não há "número" a reportar, na leitura
            // dela) em vez de 0 explícito; normalizamos aqui para não deixar
            // o cálculo final travar num null silencioso por causa disso.
            $valor = $origem === 'explicito_zero' ? ($termo['valor'] ?? 0) : $termo['valor'];

            return [
                'nome'    => $nome,
                'valor'   => $valor,
                'origem'  => $origem,
                'citacao' => $termo['citacao'] ?? null,
                'status'  => 'aceito',
            ];
        }

        if ($valorSugerido !== null) {
            return [
                'nome'           => $nome,
                'valor'          => null,
                'origem'         => 'ausente',
                'citacao'        => null,
                'status'         => 'pendente_confirmacao',
                'valor_sugerido' => $valorSugerido,
            ];
        }

        return [
            'nome'    => $nome,
            'valor'   => null,
            'origem'  => 'ausente',
            'citacao' => null,
            'status'  => 'pendente_escolha',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // MESCLA DE RESPOSTAS DO WIZARD (usado por responder-pendencias)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Aplica as respostas do wizard ao estado de termos, marcando-os como
     * 'aceito'. Não recalcula nada — só resolve o termo individual.
     *
     * @param  array $estado    Estrutura de construirEstadoInicial() (ou já mesclada anteriormente)
     * @param  array $respostas [{id: string, resposta: mixed}]
     */
    public function mesclarRespostas(array $estado, array $respostas): array
    {
        foreach ($respostas as $resposta) {
            $id = $resposta['id'] ?? null;
            $valorResposta = $resposta['resposta'] ?? null;

            if ($id === 'checagem_cruzada_custo_faturamento') {
                $estado['checagem_cruzada_confirmada'] = true;
                continue;
            }

            if ($id === 'n_funcionarios') {
                $nFuncionarios = $this->interpretarRespostaBinaria($valorResposta);
                $estado['custo_fixo_mensal']['n_funcionarios'] = [
                    'nome'   => 'n_funcionarios',
                    'valor'  => $nFuncionarios,
                    'origem' => 'respondido_pelo_lojista',
                    'citacao' => null,
                    'status' => 'aceito',
                ];
                continue;
            }

            if ($id === 'custo_total_por_funcionario') {
                if ($valorResposta === 'confirmado') {
                    $termoAtual = $estado['custo_fixo_mensal']['custo_total_por_funcionario'];
                    $estado['custo_fixo_mensal']['custo_total_por_funcionario'] = [
                        'nome'                     => 'custo_total_por_funcionario',
                        'valor'                    => $termoAtual['valor_sugerido'] ?? $termoAtual['valor'],
                        'origem'                   => 'assumido_por_regime_tributario',
                        'citacao'                  => null,
                        'status'                   => 'aceito',
                        'confirmado_pelo_usuario'  => true,
                    ];
                    continue;
                }

                if (is_numeric($valorResposta)) {
                    // Resposta à pendência de valor aberto que sucede a rejeição
                    // do valor sugerido (ver 'ajustar' abaixo) — o lojista já
                    // informou o valor real por funcionário.
                    $estado['custo_fixo_mensal']['custo_total_por_funcionario'] = [
                        'nome'    => 'custo_total_por_funcionario',
                        'valor'   => (float) $valorResposta,
                        'origem'  => 'respondido_pelo_lojista',
                        'citacao' => null,
                        'status'  => 'aceito',
                    ];
                    continue;
                }

                // 'ajustar' ("Não, é mais" / "Não, é menos"): o lojista rejeitou
                // o valor sugerido pela tabela do regime tributário — NÃO pode
                // ser aceito automaticamente. Fica pendente_ajuste para que
                // gerarPendencias() pergunte o valor real em seguida (SPEC §6.1),
                // em vez de assumir o valor sugerido de qualquer jeito.
                $estado['custo_fixo_mensal']['custo_total_por_funcionario'] = [
                    'nome'    => 'custo_total_por_funcionario',
                    'valor'   => null,
                    'origem'  => 'ausente',
                    'citacao' => null,
                    'status'  => 'pendente_ajuste',
                ];
                continue;
            }

            if (array_key_exists($id, $estado['custo_fixo_mensal'])) {
                $estado['custo_fixo_mensal'][$id] = [
                    'nome'   => $id,
                    'valor'  => is_numeric($valorResposta) ? (float) $valorResposta : $valorResposta,
                    'origem' => 'respondido_pelo_lojista',
                    'citacao' => null,
                    'status' => 'aceito',
                ];
                continue;
            }

            if (array_key_exists($id, $estado['faturamento_medio_mensal'])) {
                $termoAtual = $estado['faturamento_medio_mensal'][$id];
                $novoValor = is_numeric($valorResposta) ? (float) $valorResposta : ($termoAtual['valor_sugerido'] ?? $valorResposta);
                $estado['faturamento_medio_mensal'][$id] = [
                    'nome'    => $id,
                    'valor'   => $novoValor,
                    'origem'  => 'respondido_pelo_lojista',
                    'citacao' => null,
                    'status'  => 'aceito',
                ];
                continue;
            }

            if (array_key_exists($id, $estado['volume_vendas_esperado'])) {
                $estado['volume_vendas_esperado'][$id] = [
                    'nome'    => $id,
                    'valor'   => is_numeric($valorResposta) ? (int) $valorResposta : $valorResposta,
                    'origem'  => 'respondido_pelo_lojista',
                    'citacao' => null,
                    'status'  => 'aceito',
                ];
                continue;
            }

            // margem_lucro_desejada é uma pendência combinada (SPEC §2.3) —
            // não corresponde a um termo individual dentro do estado, então
            // a resposta sempre resolve a rota "direta" com o percentual
            // informado pelo lojista.
            if ($id === 'margem_lucro_desejada') {
                $estado['margem_lucro_desejada'] = [
                    'rota'          => 'direta',
                    'margem_direta' => [
                        'nome'    => 'margem_direta',
                        'valor'   => is_numeric($valorResposta) ? (float) $valorResposta : $valorResposta,
                        'origem'  => 'respondido_pelo_lojista',
                        'citacao' => null,
                        'status'  => 'aceito',
                    ],
                ];
            }
        }

        // Se a resposta acabou de resolver quantidade_vendida (Rota 2 do
        // faturamento), volume_vendas_esperado precisa refletir esse valor —
        // o snapshot construído em construirEstadoInicial() ficou congelado
        // com quantidade_vendida ainda ausente, então não basta reaproveitar
        // o que já estava em estado['volume_vendas_esperado'].
        if (($estado['faturamento_medio_mensal']['rota'] ?? null) === 'decomposicao') {
            $estado['volume_vendas_esperado'] = $this->construirTermosVolumeVendas([], '', $estado['faturamento_medio_mensal']);
        }

        return $estado;
    }

    private function interpretarRespostaBinaria(mixed $resposta): int
    {
        // A pergunta binária de n_funcionarios não determina a quantidade —
        // só se há ou não funcionários. "sim" sem número vira 1 (mínimo
        // plausível); o lojista pode corrigir depois na Tela 3.
        if (is_numeric($resposta)) {
            return (int) $resposta;
        }

        return in_array($resposta, ['sim', 'Sim, tenho', true], true) ? 1 : 0;
    }

    // ─────────────────────────────────────────────────────────────────────
    // GERAÇÃO DE PENDÊNCIAS (ordem de dependência lógica — SPEC §6.2)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array{pendencias: array, grupoAResolvido: bool, grupoBResolvido: bool}
     */
    public function gerarPendencias(array $estado): array
    {
        $custoFixo = $estado['custo_fixo_mensal'];
        $pendencias = [];

        // ── Grupo A ──────────────────────────────────────────────────────
        $this->adicionarPendenciaSe($pendencias, $custoFixo['custos_do_local'], [
            'tipo'     => 'confirmacao_valor_faixa',
            'pergunta' => 'Quanto você paga de aluguel e contas fixas do local (água, luz, internet)?',
            'depende_de' => null,
        ]);

        $this->adicionarPendenciaSe($pendencias, $custoFixo['n_funcionarios'], [
            'tipo'     => 'confirmacao_binaria',
            'pergunta' => 'Sua loja tem funcionários contratados?',
            'opcoes'   => ['Não tenho', 'Sim, tenho'],
            'depende_de' => null,
        ]);

        // n_funcionarios > 0? explícito no texto OU respondido "sim" — só então
        // custo_total_por_funcionario entra na lista.
        $nFuncionariosResolvido = $custoFixo['n_funcionarios']['status'] === 'aceito';
        $nFuncionarios = (int) ($custoFixo['n_funcionarios']['valor'] ?? 0);

        if ($nFuncionariosResolvido && $nFuncionarios > 0) {
            if (($custoFixo['custo_total_por_funcionario']['status'] ?? null) === 'pendente_ajuste') {
                // O lojista rejeitou o valor sugerido pela tabela do regime
                // tributário ("Não, é mais"/"Não, é menos") — pergunta o valor
                // real em vez de simplesmente pular para o próximo grupo.
                $pendencias[] = [
                    'id'         => 'custo_total_por_funcionario',
                    'tipo'       => 'confirmacao_valor_faixa',
                    'pergunta'   => 'Qual o custo total (salário + encargos) por funcionário, em média?',
                    'depende_de' => 'n_funcionarios',
                ];
            } else {
                $this->adicionarPendenciaSe($pendencias, $custoFixo['custo_total_por_funcionario'], [
                    'tipo'     => 'confirmacao_valor_sugerido',
                    'pergunta' => sprintf(
                        'Consideramos um custo total de R$ %s por funcionário. Esse valor está próximo do que você paga?',
                        number_format((float) ($custoFixo['custo_total_por_funcionario']['valor_sugerido'] ?? 0), 2, ',', '.')
                    ),
                    'opcoes'   => ['Sim, está certo', 'Não, é mais', 'Não, é menos'],
                    'valor_sugerido' => $custoFixo['custo_total_por_funcionario']['valor_sugerido'] ?? null,
                    'depende_de' => 'n_funcionarios',
                ]);
            }
        }

        $this->adicionarPendenciaSe($pendencias, $custoFixo['outros_custos_fixos'], [
            'tipo'     => 'confirmacao_valor_faixa',
            'pergunta' => 'Você tem outros custos fixos (equipamento, sistema, etc.)?',
            'depende_de' => null,
        ]);

        $custoTotalPorFuncionarioAplicavel = $nFuncionariosResolvido && $nFuncionarios > 0;
        $grupoAResolvido = $custoFixo['custos_do_local']['status'] === 'aceito'
            && $custoFixo['n_funcionarios']['status'] === 'aceito'
            && (! $custoTotalPorFuncionarioAplicavel || $custoFixo['custo_total_por_funcionario']['status'] === 'aceito')
            && $custoFixo['outros_custos_fixos']['status'] === 'aceito';

        $grupoBResolvido = false;

        // ── Grupo B (só depois de A totalmente resolvido) ────────────────
        if ($grupoAResolvido && empty($pendencias)) {
            $faturamento = $estado['faturamento_medio_mensal'];

            if (($faturamento['rota'] ?? null) === 'direto') {
                $this->adicionarPendenciaSe($pendencias, $faturamento['faturamento_direto'], [
                    'tipo'     => 'confirmacao_valor_faixa',
                    'pergunta' => 'Qual o faturamento médio mensal da sua loja?',
                    'depende_de' => null,
                ]);

                $grupoBResolvido = empty($pendencias) && $faturamento['faturamento_direto']['status'] === 'aceito';
            } else {
                $this->adicionarPendenciaSe($pendencias, $faturamento['quantidade_vendida'], [
                    'tipo'     => 'confirmacao_valor_faixa',
                    'pergunta' => 'Quantas peças você vende, em média, por mês?',
                    'depende_de' => null,
                ]);

                $this->adicionarPendenciaSe($pendencias, $faturamento['ticket_medio'], [
                    'tipo'     => 'confirmacao_valor_faixa',
                    'pergunta' => 'Qual o ticket médio (valor médio) de cada venda?',
                    'depende_de' => null,
                ]);

                if (($faturamento['periodicidade_informada'] ?? null) === 'dia' && isset($faturamento['dias_funcionamento_mes'])) {
                    $this->adicionarPendenciaSe($pendencias, $faturamento['dias_funcionamento_mes'], [
                        'tipo'     => 'confirmacao_valor_sugerido',
                        'pergunta' => sprintf(
                            'Consideramos %d dias de funcionamento por mês. Está certo?',
                            (int) ($faturamento['dias_funcionamento_mes']['valor_sugerido'] ?? self::DIAS_FUNCIONAMENTO_MES_REFERENCIA)
                        ),
                        'opcoes'   => ['Sim, está certo', 'Não, é diferente'],
                        'valor_sugerido' => $faturamento['dias_funcionamento_mes']['valor_sugerido'] ?? null,
                        'depende_de' => null,
                    ]);
                }

                $grupoBResolvido = empty($pendencias)
                    && $faturamento['quantidade_vendida']['status'] === 'aceito'
                    && $faturamento['ticket_medio']['status'] === 'aceito'
                    && (($faturamento['periodicidade_informada'] ?? null) !== 'dia'
                        || ($faturamento['dias_funcionamento_mes']['status'] ?? 'aceito') === 'aceito');
            }
        }

        // ── Grupo D: volume de vendas (Rota 1) e margem de lucro ─────────
        // Independente de A/B/C — nada depende deles e eles não dependem de
        // custo/faturamento. Só entra na fila depois que A e B estiverem
        // livres de pendência, por conveniência de fluxo (SPEC §3).
        if ($grupoAResolvido && $grupoBResolvido && empty($pendencias)) {
            $volumeVendas = $estado['volume_vendas_esperado'];

            // Rota 1 (faturamento direto): volume_vendas_esperado não tem de
            // onde ser derivado, então vira pendência própria. Na Rota 2 já
            // chega 'aceito' (derivado de quantidade_vendida) — nunca gera
            // pendência aqui.
            $this->adicionarPendenciaSe($pendencias, $volumeVendas['volume_vendas_direto'], [
                'tipo'     => 'confirmacao_valor_faixa',
                'pergunta' => 'Quantas peças você vende, em média, por mês?',
                'depende_de' => null,
            ]);

            $margemLucro = $estado['margem_lucro_desejada'];
            $margemResolvida = ($margemLucro['rota'] ?? null) === 'direta'
                ? $margemLucro['margem_direta']['status'] === 'aceito'
                : ($margemLucro['preco_custo']['status'] === 'aceito' && $margemLucro['preco_venda']['status'] === 'aceito');

            // Nenhuma das duas rotas trouxe dado suficiente: uma única
            // pendência combinada, campo percentual aberto — não perguntamos
            // preço de custo/venda separadamente (SPEC §2.3).
            if (! $margemResolvida && empty($pendencias)) {
                $pendencias[] = [
                    'id'         => 'margem_lucro_desejada',
                    'tipo'       => 'confirmacao_valor_faixa',
                    'pergunta'   => 'Não conseguimos identificar sua margem de lucro desejada no texto. Qual margem você pretende praticar?',
                    'depende_de' => null,
                ];
            }
        }

        // ── Grupo C: checagem cruzada (só depois de A e B resolvidos) ────
        if ($grupoAResolvido && $grupoBResolvido && empty($pendencias)) {
            $custoCalculado = $this->calcularCustoFixo($estado['custo_fixo_mensal']);
            $faturamentoCalculado = $this->calcularFaturamento($estado['faturamento_medio_mensal']);

            if ($custoCalculado !== null
                && $faturamentoCalculado !== null
                && $faturamentoCalculado < $custoCalculado
                && empty($estado['checagem_cruzada_confirmada'])
            ) {
                $pendencias[] = [
                    'id'         => 'checagem_cruzada_custo_faturamento',
                    'tipo'       => 'confirmacao_agregada',
                    'pergunta'   => sprintf(
                        'Você informou custos de R$ %s e faturamento de R$ %s — isso significa que a loja está operando no vermelho no momento. Confere?',
                        number_format($custoCalculado, 2, ',', '.'),
                        number_format($faturamentoCalculado, 2, ',', '.')
                    ),
                    'opcoes'     => ['Sim, é isso mesmo', 'Não, deixa eu revisar os valores'],
                    'depende_de' => null,
                ];
            }
        }

        return [
            'pendencias'      => $pendencias,
            'grupoAResolvido' => $grupoAResolvido,
            'grupoBResolvido' => $grupoBResolvido,
        ];
    }

    private function adicionarPendenciaSe(array &$pendencias, array $termo, array $extra): void
    {
        if ($termo['status'] === 'aceito') {
            return;
        }

        $tipo = $termo['status'] === 'pendente_confirmacao' ? ($extra['tipo'] === 'confirmacao_valor_faixa' ? 'confirmacao_valor_sugerido' : $extra['tipo']) : $extra['tipo'];

        $pendencias[] = array_merge([
            'id'   => $termo['nome'],
            'tipo' => $tipo,
        ], $extra);
    }

    // ─────────────────────────────────────────────────────────────────────
    // CÁLCULO FINAL (SPEC §3)
    // ─────────────────────────────────────────────────────────────────────

    public function calcularCustoFixo(array $termosCustoFixo): ?float
    {
        $custosDoLocal = $termosCustoFixo['custos_do_local']['valor'] ?? null;
        $outrosCustos = $termosCustoFixo['outros_custos_fixos']['valor'] ?? null;
        $nFuncionarios = $termosCustoFixo['n_funcionarios']['valor'] ?? null;

        if ($custosDoLocal === null || $outrosCustos === null || $nFuncionarios === null) {
            return null;
        }

        $custoPorFuncionario = 0.0;
        if ($nFuncionarios > 0) {
            $custoPorFuncionario = $termosCustoFixo['custo_total_por_funcionario']['valor'] ?? null;
            if ($custoPorFuncionario === null) {
                return null;
            }
        }

        return (float) $custosDoLocal + (float) $outrosCustos + ($nFuncionarios * (float) $custoPorFuncionario);
    }

    /**
     * volume_vendas_esperado: se derivado da Rota 2 do faturamento, é o
     * mesmo valor de quantidade_vendida (já calculado em
     * construirTermosVolumeVendas); senão, é o termo próprio
     * volume_vendas_direto (Rota 1).
     */
    public function calcularVolumeVendas(array $termosVolumeVendas): ?int
    {
        $valor = $termosVolumeVendas['volume_vendas_direto']['valor'] ?? null;

        return $valor !== null ? (int) $valor : null;
    }

    /**
     * margem_lucro_desejada: valor direto, ou calculada deterministicamente
     * a partir dos preços de referência — margem sobre o preço de venda,
     * (preco_venda - preco_custo) / preco_venda, mantida como fração
     * decimal (mesma unidade já usada por OnboardingGuardrail::RANGES).
     */
    public function calcularMargemLucro(array $termosMargemLucro): ?float
    {
        if (($termosMargemLucro['rota'] ?? null) === 'direta') {
            $margemDireta = $termosMargemLucro['margem_direta']['valor'] ?? null;

            return $margemDireta !== null ? (float) $margemDireta / 100 : null;
        }

        $precoCusto = $termosMargemLucro['preco_custo']['valor'] ?? null;
        $precoVenda = $termosMargemLucro['preco_venda']['valor'] ?? null;

        if ($precoCusto === null || $precoVenda === null || (float) $precoVenda <= 0.0) {
            return null;
        }

        return ((float) $precoVenda - (float) $precoCusto) / (float) $precoVenda;
    }

    public function calcularFaturamento(array $termosFaturamento): ?float
    {
        if (($termosFaturamento['rota'] ?? null) === 'direto') {
            return $termosFaturamento['faturamento_direto']['valor'] ?? null;
        }

        $quantidade = $termosFaturamento['quantidade_vendida']['valor'] ?? null;
        $ticket = $termosFaturamento['ticket_medio']['valor'] ?? null;
        $periodicidade = $termosFaturamento['periodicidade_informada'] ?? null;

        if ($quantidade === null || $ticket === null || $periodicidade === null) {
            return null;
        }

        $totalPorPeriodo = (float) $quantidade * (float) $ticket;

        return match ($periodicidade) {
            'mes'    => $totalPorPeriodo,
            'semana' => $totalPorPeriodo * 4,
            'dia'    => $totalPorPeriodo * (float) ($termosFaturamento['dias_funcionamento_mes']['valor'] ?? self::DIAS_FUNCIONAMENTO_MES_REFERENCIA),
            default  => null,
        };
    }

    /**
     * Monta o bloco de `termos_detalhados` no formato de persistência
     * (SPEC §7), a partir do estado de termos + valores finais calculados.
     */
    public function montarTermosDetalhados(
        array $estado,
        ?float $custoFixoMensal,
        ?float $faturamentoMedioMensal,
        ?int $volumeVendasEsperado = null,
        ?float $margemLucroDesejada = null,
    ): array {
        return [
            'custo_fixo_mensal' => [
                'valor'        => $custoFixoMensal,
                'origem_dado'  => $this->origemAgregada($estado['custo_fixo_mensal']),
                'termos'       => array_values($estado['custo_fixo_mensal']),
            ],
            'faturamento_medio_mensal' => [
                'valor'       => $faturamentoMedioMensal,
                'origem_dado' => $this->origemAgregada($this->apenasTermos($estado['faturamento_medio_mensal'])),
                'termos'      => array_values($this->apenasTermos($estado['faturamento_medio_mensal'])),
            ],
            'volume_vendas_esperado' => [
                'valor'       => $volumeVendasEsperado,
                'origem_dado' => $this->origemAgregada($this->apenasTermos($estado['volume_vendas_esperado'])),
                'termos'      => array_values($this->apenasTermos($estado['volume_vendas_esperado'])),
            ],
            'margem_lucro_desejada' => [
                'valor'       => $margemLucroDesejada,
                'origem_dado' => $this->origemAgregada($this->apenasTermos($estado['margem_lucro_desejada'])),
                'termos'      => array_values($this->apenasTermos($estado['margem_lucro_desejada'])),
            ],
        ];
    }

    /**
     * Filtra as chaves auxiliares (ex: 'rota') do bloco de termos de um
     * campo, mantendo só as entradas que de fato representam um termo
     * (têm 'status').
     */
    private function apenasTermos(array $bloco): array
    {
        return array_filter($bloco, fn ($v) => is_array($v) && isset($v['status']));
    }

    private function origemAgregada(array $termos): string
    {
        $origens = array_column($termos, 'origem');

        // 'derivado_de_faturamento' é um valor de auditoria próprio — sinaliza
        // que o dado não veio de uma extração de IA independente, e sim de
        // outro termo já resolvido (volume_vendas_esperado a partir da Rota 2
        // do faturamento). Não deve ser confundido com 'extraido_do_texto'.
        if (in_array('derivado_de_faturamento', $origens, true)) {
            return 'derivado_de_faturamento';
        }

        if (in_array('respondido_pelo_lojista', $origens, true) || in_array('assumido_por_regime_tributario', $origens, true)) {
            return 'assumido_confirmado';
        }

        return 'extraido_do_texto';
    }
}
