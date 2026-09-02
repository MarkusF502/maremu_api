<?php

namespace App\Http\Controllers;

use App\Http\Requests\OnboardingAnalisarTextoRequest;
use App\Http\Requests\OnboardingIaConfirmarRequest;
use App\Http\Requests\OnboardingResponderPendenciasRequest;
use App\Models\CanalVendaLoja;
use App\Models\LogsOnboardingIa;
use App\Models\Loja;
use App\Services\OnboardingGuardrail;
use App\Services\OnboardingIaInterface;
use App\Services\OnboardingService;
use App\Services\OnboardingTermosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * OnboardingIaController
 *
 * Orquestra o fluxo de onboarding via IA (SPEC-onboarding-ia): Tela 2
 * (texto dissertativo) → estimativas → Tela 3 (revisão e confirmação).
 *
 * Nunca persiste a Loja em analisarTexto() — só em confirmar(), depois que
 * o lojista revisou/editou tudo (SPEC D1).
 */
class OnboardingIaController extends Controller
{
    public function __construct(
        private readonly OnboardingIaInterface $onboardingIaService,
        private readonly OnboardingGuardrail $guardrail,
        private readonly OnboardingService $onboardingService,
        private readonly OnboardingTermosService $termosService,
    ) {}

    /**
     * POST /api/loja/onboarding/analisar-texto
     */
    public function analisarTexto(OnboardingAnalisarTextoRequest $request): JsonResponse
    {
        $user = $request->user();

        $dadosFactuais = [
            'nome_loja'         => $request->nome_loja,
            'regime_tributario' => $request->regime_tributario,
            'canais_marcados'   => $request->canais_marcados,
        ];

        // Guardrail determinístico pré-API — evita gastar quota do Gemini
        // com texto ruído (SPEC §6.2 passo 2 / §10).
        if (! $this->guardrail->textoSuficiente($request->texto_descritivo)) {
            $log = LogsOnboardingIa::create([
                'user_id'         => $user->id,
                'texto_original'  => $request->texto_descritivo,
                'dados_factuais'  => $dadosFactuais,
                'usou_fallback'   => true,
                'motivo_fallback' => 'texto_insuficiente',
            ]);

            return response()->json([
                'sucesso'  => false,
                'fallback' => true,
                'motivo'   => 'texto_insuficiente',
                'log_id'   => $log->id,
            ]);
        }

        try {
            $resultado = $this->onboardingIaService->estimarDadosLoja($request->texto_descritivo, $dadosFactuais);
        } catch (RuntimeException $e) {
            Log::error('Falha ao estimar dados do onboarding via Gemini', [
                'user_id' => $user->id,
                'erro'    => $e->getMessage(),
            ]);

            $log = LogsOnboardingIa::create([
                'user_id'         => $user->id,
                'texto_original'  => $request->texto_descritivo,
                'dados_factuais'  => $dadosFactuais,
                'usou_fallback'   => true,
                'motivo_fallback' => 'erro_api',
            ]);

            return response()->json([
                'sucesso'  => false,
                'fallback' => true,
                'motivo'   => 'erro_api',
                'log_id'   => $log->id,
            ]);
        }

        if (! $resultado['confianca_suficiente']) {
            $log = LogsOnboardingIa::create([
                'user_id'         => $user->id,
                'texto_original'  => $request->texto_descritivo,
                'dados_factuais'  => $dadosFactuais,
                'estimativas_ia'  => $resultado,
                'usou_fallback'   => true,
                'motivo_fallback' => 'confianca_insuficiente',
            ]);

            return response()->json([
                'sucesso'  => false,
                'fallback' => true,
                'motivo'   => 'confianca_insuficiente',
                'log_id'   => $log->id,
            ]);
        }

        // Spec-Extracao-Assertiva-Onboarding-Maremu §3: custo_fixo_mensal e
        // faturamento_medio_mensal não vêm mais como número final da IA —
        // vêm como termos componentes com citação, que o backend valida
        // (§5) e roteia deterministicamente (§6).
        $estado = $this->termosService->construirEstadoInicial(
            termosCustoFixoIa: $resultado['termos_custo_fixo'] ?? [],
            termosFaturamentoIa: $resultado['termos_faturamento'] ?? [],
            textoOriginal: $request->texto_descritivo,
            regimeTributario: $request->regime_tributario,
            termosVolumeVendasIa: $resultado['termos_volume_vendas'] ?? [],
            termosMargemLucroIa: $resultado['termos_margem_lucro'] ?? [],
        );

        $roteamento = $this->termosService->gerarPendencias($estado);
        $pendencias = $roteamento['pendencias'];

        $custoFixoMensal = null;
        $faturamentoMedioMensal = null;
        $volumeVendasEsperado = null;
        $margemLucroDesejada = null;
        $status = 'pendente';

        if (empty($pendencias)) {
            [$custoFixoMensal, $faturamentoMedioMensal, $volumeVendasEsperado, $margemLucroDesejada] = $this->calcularValoresFinais($estado);
            $status = 'concluido';
        }

        $log = LogsOnboardingIa::create([
            'user_id'           => $user->id,
            'texto_original'    => $request->texto_descritivo,
            'dados_factuais'    => $dadosFactuais,
            'estimativas_ia'    => $resultado,
            'usou_fallback'     => false,
            'termos_detalhados' => array_merge(
                $this->termosService->montarTermosDetalhados($estado, $custoFixoMensal, $faturamentoMedioMensal, $volumeVendasEsperado, $margemLucroDesejada),
                ['_estado_interno' => $estado]
            ),
            'status'            => $status,
        ]);

        $estimativasResolvidas = $resultado['estimativas'];
        $estimativasResolvidas['custo_fixo_mensal'] = ['valor' => $custoFixoMensal, 'explicacao' => 'Calculado a partir dos itens que você descreveu.'];
        $estimativasResolvidas['faturamento_medio_mensal'] = ['valor' => $faturamentoMedioMensal, 'explicacao' => 'Calculado a partir dos itens que você descreveu.'];
        $estimativasResolvidas['volume_vendas_esperado'] = ['valor' => $volumeVendasEsperado, 'explicacao' => 'Calculado a partir dos itens que você descreveu.'];
        $estimativasResolvidas['margem_lucro_desejada'] = ['valor' => $margemLucroDesejada, 'explicacao' => 'Calculado a partir dos itens que você descreveu.'];

        return response()->json([
            'sucesso'               => true,
            'sessao_id'             => $log->id,
            'log_id'                => $log->id,
            'estimativas_resolvidas'=> $estimativasResolvidas,
            'estimativas'           => $estimativasResolvidas,
            'pendencias'            => $pendencias,
            'canais_sugeridos'      => $resultado['canais_sugeridos'],
        ]);
    }

    /**
     * POST /api/loja/onboarding/responder-pendencias
     *
     * Nunca chama IA (SPEC §8.2) — lê o draft de termos salvo em
     * analisar-texto, mescla as respostas do wizard e reusa a mesma função
     * de roteamento/cálculo determinístico.
     */
    public function responderPendencias(OnboardingResponderPendenciasRequest $request): JsonResponse
    {
        $user = $request->user();

        $log = LogsOnboardingIa::where('user_id', $user->id)->findOrFail($request->sessao_id);

        $estado = $log->termos_detalhados['_estado_interno'] ?? null;

        if ($estado === null) {
            return response()->json(['message' => 'Sessão de onboarding inválida.'], 422);
        }

        $estado = $this->termosService->mesclarRespostas($estado, $request->respostas);

        $roteamento = $this->termosService->gerarPendencias($estado);
        $pendencias = $roteamento['pendencias'];

        if (! empty($pendencias)) {
            $log->update([
                'termos_detalhados' => array_merge(
                    $this->termosService->montarTermosDetalhados($estado, null, null, null, null),
                    ['_estado_interno' => $estado]
                ),
                'status' => 'pendente',
            ]);

            return response()->json([
                'status'               => 'pendente',
                'sessao_id'            => $log->id,
                'pendencias_restantes' => $pendencias,
            ]);
        }

        [$custoFixoMensal, $faturamentoMedioMensal, $volumeVendasEsperado, $margemLucroDesejada] = $this->calcularValoresFinais($estado);

        $estimativasIa = $log->estimativas_ia ?? [];
        $estimativasIa['estimativas']['volume_vendas_esperado'] = ['valor' => $volumeVendasEsperado, 'explicacao' => 'Calculado a partir dos itens que você descreveu.'];
        $estimativasIa['estimativas']['margem_lucro_desejada'] = ['valor' => $margemLucroDesejada, 'explicacao' => 'Calculado a partir dos itens que você descreveu.'];

        $log->update([
            'termos_detalhados' => array_merge(
                $this->termosService->montarTermosDetalhados($estado, $custoFixoMensal, $faturamentoMedioMensal, $volumeVendasEsperado, $margemLucroDesejada),
                ['_estado_interno' => $estado]
            ),
            'estimativas_ia' => $estimativasIa,
            'status'         => 'concluido',
        ]);

        return response()->json([
            'status'                   => 'concluido',
            'sessao_id'                => $log->id,
            'custo_fixo_mensal'        => $custoFixoMensal,
            'faturamento_medio_mensal' => $faturamentoMedioMensal,
            'volume_vendas_esperado'   => $volumeVendasEsperado,
            'margem_lucro_desejada'    => $margemLucroDesejada,
            'redirecionar_para'        => 'tela_2',
        ]);
    }

    /**
     * Calcula custo_fixo_mensal, faturamento_medio_mensal,
     * volume_vendas_esperado e margem_lucro_desejada a partir do estado de
     * termos já totalmente resolvido, aplicando o mesmo OnboardingGuardrail
     * (clamp) usado para as demais estimativas — agora sobre o valor final,
     * não mais por termo (SPEC: fora de escopo não alterar o guardrail em
     * si, só o que ele recebe).
     *
     * @return array{0: ?float, 1: ?float, 2: ?int, 3: ?float}
     */
    private function calcularValoresFinais(array $estado): array
    {
        $custoFixoMensal = $this->termosService->calcularCustoFixo($estado['custo_fixo_mensal']);
        $faturamentoMedioMensal = $this->termosService->calcularFaturamento($estado['faturamento_medio_mensal']);
        $volumeVendasEsperado = $this->termosService->calcularVolumeVendas($estado['volume_vendas_esperado']);
        $margemLucroDesejada = $this->termosService->calcularMargemLucro($estado['margem_lucro_desejada']);

        if ($custoFixoMensal !== null) {
            $custoFixoMensal = $this->guardrail->clampar('custo_fixo_mensal', $custoFixoMensal)['valor'];
        }

        if ($faturamentoMedioMensal !== null) {
            $faturamentoMedioMensal = $this->guardrail->clampar('faturamento_medio_mensal', $faturamentoMedioMensal)['valor'];
        }

        if ($volumeVendasEsperado !== null) {
            $volumeVendasEsperado = $this->guardrail->clampar('volume_vendas_esperado', $volumeVendasEsperado)['valor'];
        }

        if ($margemLucroDesejada !== null) {
            $margemLucroDesejada = $this->guardrail->clampar('margem_lucro_desejada', $margemLucroDesejada)['valor'];
        }

        return [$custoFixoMensal, $faturamentoMedioMensal, $volumeVendasEsperado, $margemLucroDesejada];
    }

    /**
     * POST /api/loja/onboarding/confirmar-ia
     *
     * Persiste a Loja com os valores confirmados/editados pelo lojista.
     * A alíquota efetiva e a taxa dos canais continuam determinísticas —
     * a IA nunca as estima (SPEC D7).
     */
    public function confirmar(OnboardingIaConfirmarRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->loja()->exists()) {
            return response()->json([
                'message' => 'Você já possui uma loja cadastrada.',
                'code'    => 'loja_ja_existe',
            ], 422);
        }

        $log = LogsOnboardingIa::where('user_id', $user->id)->findOrFail($request->log_id);

        $estimativasIa = $log->estimativas_ia['estimativas'] ?? [];
        // Nenhum dos quatro campos abaixo vem mais direto da IA
        // (SPEC-Extracao-Assertiva-Onboarding) — vêm do valor final
        // calculado a partir dos termos, já persistido em termos_detalhados.
        foreach (['custo_fixo_mensal', 'faturamento_medio_mensal', 'volume_vendas_esperado', 'margem_lucro_desejada'] as $campo) {
            $estimativasIa[$campo] = ['valor' => $log->termos_detalhados[$campo]['valor'] ?? null];
        }

        $camposEstimados = [
            'posicionamento'           => $request->posicionamento,
            'faturamento_medio_mensal' => (float) $request->faturamento_medio_mensal,
            'custo_fixo_mensal'        => (float) $request->custo_fixo_mensal,
            'margem_lucro_desejada'    => (float) $request->margem_lucro_desejada,
            'volume_vendas_esperado'   => (int) $request->volume_vendas_esperado,
        ];

        $origens = [];
        foreach ($camposEstimados as $campo => $valorFinal) {
            $valorIa = $estimativasIa[$campo]['valor'] ?? null;
            // Reaproveita o enum já existente (D4): 'estimado_pelo_sistema'
            // quando o lojista não mexeu no valor sugerido pela IA,
            // 'editado_pelo_lojista' quando ele alterou.
            $origens[$campo] = ($valorIa !== null && $this->valoresIguais($valorIa, $valorFinal))
                ? 'estimado_pelo_sistema'
                : 'editado_pelo_lojista';
        }

        // Alíquota efetiva: 100% determinística, a partir de regime +
        // faturamento confirmado — mesmo cálculo do OnboardingService atual.
        $aliquotaEfetiva = $this->onboardingService->inferirDadosDaLoja(
            faixaFaturamento: $this->faixaParaFaturamento($camposEstimados['faturamento_medio_mensal']),
            posicionamento:   $camposEstimados['posicionamento'],
            regime:           $request->regime_tributario,
            canais:           $request->canais,
        )['loja']['aliquota_efetiva'];

        $canaisDeterministicos = $this->onboardingService->inferirCanaisPublico($request->canais);

        $loja = DB::transaction(function () use ($request, $user, $camposEstimados, $origens, $aliquotaEfetiva, $canaisDeterministicos, $log) {
            $loja = Loja::create([
                'user_id'                  => $user->id,
                'nome'                     => $request->nome,
                'posicionamento'           => $camposEstimados['posicionamento'],
                'regime_tributario'        => $request->regime_tributario,
                'faturamento_medio_mensal' => $camposEstimados['faturamento_medio_mensal'],
                'custo_fixo_mensal'        => $camposEstimados['custo_fixo_mensal'],
                'custo_fixo_origem'        => $origens['custo_fixo_mensal'],
                'margem_lucro_desejada'    => $camposEstimados['margem_lucro_desejada'],
                'aliquota_efetiva'         => $aliquotaEfetiva,
                'aliquota_origem'          => 'estimado_pelo_sistema',
                'volume_vendas_esperado'   => $camposEstimados['volume_vendas_esperado'],
            ]);

            foreach ($canaisDeterministicos as $canal) {
                CanalVendaLoja::create([
                    'loja_id'         => $loja->id,
                    'canal'           => $canal['canal'],
                    'taxa_percentual' => $canal['taxa_percentual'],
                    'taxa_origem'     => $canal['taxa_origem'],
                    'ativo'           => true,
                ]);
            }

            $log->update([
                'loja_id'             => $loja->id,
                'estimativas_finais'  => array_merge($camposEstimados, ['canais' => $request->canais]),
            ]);

            return $loja;
        });

        return response()->json([
            'sucesso' => true,
            'loja_id' => $loja->id,
            'loja'    => $loja->load('canais'),
        ], 201);
    }

    private function valoresIguais(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.0001;
        }

        return $a === $b;
    }

    /**
     * Converte um faturamento confirmado em faixa, para reaproveitar a
     * tabela ALIQUOTA_POR_REGIME já validada de OnboardingService — a
     * alíquota depende só do regime nessa tabela, então a faixa escolhida
     * aqui não altera o resultado; existe só para reusar a interface
     * pública existente sem duplicar a tabela.
     */
    private function faixaParaFaturamento(float $faturamento): string
    {
        return match (true) {
            $faturamento <= 10000 => 'ate_10k',
            $faturamento <= 30000 => 'de_10k_a_30k',
            $faturamento <= 80000 => 'de_30k_a_80k',
            default                => 'acima_80k',
        };
    }
}
