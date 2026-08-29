<?php

namespace App\Http\Controllers;

use App\Http\Requests\OnboardingAnalisarTextoRequest;
use App\Http\Requests\OnboardingIaConfirmarRequest;
use App\Models\CanalVendaLoja;
use App\Models\LogsOnboardingIa;
use App\Models\Loja;
use App\Services\OnboardingGuardrail;
use App\Services\OnboardingIaInterface;
use App\Services\OnboardingService;
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

        $log = LogsOnboardingIa::create([
            'user_id'        => $user->id,
            'texto_original' => $request->texto_descritivo,
            'dados_factuais' => $dadosFactuais,
            'estimativas_ia' => $resultado,
            'usou_fallback'  => false,
        ]);

        return response()->json([
            'sucesso'          => true,
            'log_id'           => $log->id,
            'estimativas'      => $resultado['estimativas'],
            'canais_sugeridos' => $resultado['canais_sugeridos'],
        ]);
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
