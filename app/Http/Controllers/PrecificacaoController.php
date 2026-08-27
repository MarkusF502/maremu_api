<?php

namespace App\Http\Controllers;

use App\Models\LogsSugestaoIa;
use App\Models\Produto;
use App\Services\PrecificacaoPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PrecificacaoController
 *
 * Fase 2: monta e retorna o payload estruturado das camadas 1, 2 e 4.
 * Fase 5 (futura): acrescentará a chamada real ao Gemini aqui,
 *   aproveitando o payload já montado.
 *
 * Rota: POST /api/precificacao/sugerir/{produto}
 */
class PrecificacaoController extends Controller
{
    public function __construct(
        private readonly PrecificacaoPayloadService $payloadService,
        private readonly \App\Services\GeminiService $geminiService,
        private readonly \App\Services\PricingEngine $pricingEngine,
        private readonly \App\Services\MargemLiquidacaoService $margemLiquidacaoService,
    ) {}

    public function sugerir(Request $request, string $produtoId): JsonResponse
    {
        // Carrega o produto com todos os relacionamentos necessários de uma vez
        // Evita N+1: uma query com JOINs em vez de múltiplas consultas separadas
        $produto = Produto::with(['loja.canaisAtivos', 'categoria'])
            ->where('id', $produtoId)
            ->where('loja_id', $request->user()->loja->id) // garante que pertence à loja do usuário
            ->firstOrFail();

        // Valida que o preço piso foi calculado (não deve chegar aqui sem ele)
        if (is_null($produto->preco_piso)) {
            return response()->json([
                'message' => 'Preço piso não calculado para este produto.',
                'code'    => 'preco_piso_ausente',
            ], 422);
        }

        // Monta o payload estruturado das três camadas
        $payload = $this->payloadService->montar($produto);

        // Margem de liquidação: decisão determinística do sistema (Condição C —
        // ver Relatório de Testes de Variância §6), calculada ANTES da chamada
        // à IA. A IA recebe o valor já definido para poder explicá-lo, mas
        // nunca decide o número — mesmo para o cenário de liquidação.
        // instrucao_liquidacao fica fora da numeração de camadas porque não é
        // dado de negócio bruto: é uma decisão já tomada pelo sistema.
        $instrucaoLiquidacao = $this->margemLiquidacaoService->calcular(
            razaoMargem: $payload['camada_4']['razao_margem'] ?? null,
            razaoGiro: $payload['camada_4']['razao_giro'] ?? null,
            posicionamento: $produto->loja->posicionamento,
        );

        $payload['instrucao_liquidacao'] = [
            'margem_definida' => $instrucaoLiquidacao['margem'],
            'origem'          => $instrucaoLiquidacao['origem'], // 'score' | 'fallback_posicionamento'
            'score'           => $instrucaoLiquidacao['score'],  // int|null — auditoria/QA, ver Relatório §6
        ];

        // Salva o log antes de qualquer chamada externa. instrucao_liquidacao
        // (com origem/score) já vai dentro de payload_enviado — sem migration
        // nova, conforme decidido.
        $log = LogsSugestaoIa::create([
            'produto_id'      => $produto->id,
            'payload_enviado' => $payload,
        ]);

        try {
            $resultado = $this->geminiService->sugerirCenarios($payload);
        } catch (\Throwable $e) {
            Log::error('Falha ao obter sugestão de preço via Gemini', [
                'log_id' => $log->id,
                'erro'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível gerar sugestões de preço no momento. Tente novamente em instantes.',
            ], 502);
        }

        // A IA devolveu apenas a margem de cada cenário (H1 — ver Especificação
        // Técnica §3.4). O preço final é sempre calculado aqui, de forma
        // determinística, para que explicação (IA) e preço (PHP) nunca divirjam.
        // Para o cenário de liquidação, a margem em si também nunca vem da IA
        // (ver instrucao_liquidacao acima) — ver Relatório de Testes de
        // Variância §6.
        $cenarios = $this->calcularPrecosDosCenarios(
            $resultado['cenarios'],
            $produto,
            $payload['instrucao_liquidacao']['margem_definida'],
        );

        $log->update(['cenarios_retornados' => $cenarios]);

        return response()->json([
            'log_id'   => $log->id,
            'cenarios' => $cenarios,
        ]);
    }

    /**
     * Converte a margem_lucro_percentual de cada cenário em preco_sugerido,
     * via PricingEngine. A IA nunca calcula o preço final — ver
     * GeminiService::sugerirCenarios().
     *
     * Para tipo === 'liquidacao', a margem usada é sempre
     * $margemLiquidacaoDefinida (calculada por MargemLiquidacaoService antes
     * da chamada à IA), nunca o valor que a IA ecoou em
     * margem_lucro_percentual. A IA só recebeu esse valor para poder
     * explicá-lo (instrucao_liquidacao) — o preço final do cenário de
     * liquidação nunca depende da resposta da IA, nem para "conferir se ela
     * concordou". Isso é defense-in-depth: dado financeiro nunca é confiado
     * a texto gerado por modelo, mesmo quando o modelo só está repetindo um
     * número que já veio pronto (ver Relatório de Testes de Variância §6).
     *
     * @param  array<array{id: string, tipo: string, margem_lucro_percentual: float, explicacao: string}>  $cenariosIa
     * @param  float  $margemLiquidacaoDefinida  instrucao_liquidacao.margem_definida
     * @return array<array{id: string, tipo: string, margem_lucro_percentual: float, explicacao: string, preco_sugerido: float, preco_piso_referencia: float}>
     */
    private function calcularPrecosDosCenarios(array $cenariosIa, Produto $produto, float $margemLiquidacaoDefinida): array
    {
        $loja         = $produto->loja;
        $taxaCanal    = $this->taxaCanalAplicavel($loja);

        foreach ($cenariosIa as &$cenario) {
            if ($cenario['tipo'] === 'liquidacao') {
                $margemEcoada = (float) $cenario['margem_lucro_percentual'];

                // A IA deveria apenas ecoar instrucao_liquidacao.margem_definida
                // (ver prompt em GeminiService). Se divergir, é sinal de prompt
                // drift — dado útil de QA — mas nunca bloqueia nem usa o valor
                // divergente.
                if (abs($margemEcoada - $margemLiquidacaoDefinida) > 0.0001) {
                    Log::warning('Gemini ecoou margem_lucro_percentual divergente de instrucao_liquidacao.margem_definida no cenário de liquidação.', [
                        'produto_id'      => $produto->id,
                        'margem_ecoada'   => $margemEcoada,
                        'margem_definida' => $margemLiquidacaoDefinida,
                    ]);
                }

                $margem = $margemLiquidacaoDefinida;
            } else {
                // cenario_ideal e cenario_alta_demanda: decisão livre da IA
                // (Condição B, CV ~0 — ver Relatório de Testes de Variância §4.1).
                $margem = (float) $cenario['margem_lucro_percentual'];
            }

            $calculo = $this->pricingEngine->calcularPreco(
                custoAquisicao: (float) $produto->custo_aquisicao,
                freteEntradaUnitario: (float) $produto->frete_entrada_unitario,
                aliquotaEfetiva: (float) $loja->aliquota_efetiva,
                taxaCanal: $taxaCanal,
                custoFixoMensal: (float) $loja->custo_fixo_mensal,
                volumeVendasEsperado: (int) $loja->volume_vendas_esperado,
                margemLucroDesejada: $margem,
            );

            $cenario['margem_aplicada']        = $margem;
            $cenario['preco_sugerido']         = $calculo['preco_venda'];
            $cenario['preco_piso_referencia']  = $calculo['preco_piso'];
        }

        return $cenariosIa;
    }

    /**
     * Taxa de canal única para o cálculo do PricingEngine, quando a loja tem
     * múltiplos canais ativos. Mesma abordagem conservadora usada no
     * onboarding (OnboardingService::taxaCanalConservadora): usa a maior
     * taxa entre os canais ativos, garantindo que o preço cobre o canal
     * mais caro. O lojista pode ajustar manualmente depois.
     */
    private function taxaCanalAplicavel($loja): float
    {
        $taxas = $loja->canaisAtivos->pluck('taxa_percentual')->map(fn ($t) => (float) $t);

        if ($taxas->isEmpty()) {
            throw new \RuntimeException("Loja {$loja->id} sem canais de venda ativos — não é possível calcular preço.");
        }

        return $taxas->max();
    }

    /**
     * Salva a escolha do lojista após visualizar os cenários da IA.
     * Atualiza o log e o preço_venda_atual do produto.
     *
     * Rota: POST /api/precificacao/confirmar
     */
    public function confirmar(Request $request): JsonResponse
    {
        $request->validate([
            'log_id'          => ['required', 'exists:logs_sugestao_ia,id'],
            'cenario_escolhido' => ['required', 'in:cenario_1,cenario_2,cenario_3,manual'],
            'preco_final'     => ['required', 'numeric', 'min:0.01'],
        ]);

        $log = LogsSugestaoIa::findOrFail($request->log_id);

        // Garante que o log pertence a um produto da loja do usuário
        $log->loadMissing('produto');
        abort_if($log->produto->loja_id !== $request->user()->loja->id, 403);

        if ($request->cenario_escolhido !== 'manual') {
        $cenarios = collect($log->cenarios_retornados ?? []);
        $cenario  = $cenarios->firstWhere('id', $request->cenario_escolhido);

        if (! $cenario || abs($cenario['preco_sugerido'] - $request->preco_final) > 0.01) {
            return response()->json([
                'message' => 'O preço enviado não corresponde ao cenário sugerido pela IA.',
            ], 422);
        }
        }

        $precoOrigem = $request->cenario_escolhido === 'manual'
            ? 'manual'
            : 'ia_' . $request->cenario_escolhido;

        // Atualiza o log com a escolha
        $log->update([
            'cenario_escolhido'      => $request->cenario_escolhido,
            'preco_final_escolhido'  => $request->preco_final,
        ]);

        // Atualiza o preço e a origem no produto
        $log->produto->update([
            'preco_venda_atual' => $request->preco_final,
            'preco_origem'      => $precoOrigem,
        ]);

        return response()->json([
            'message'     => 'Preço confirmado com sucesso.',
            'produto_id'  => $log->produto_id,
            'preco_final' => $request->preco_final,
        ]);
    }
}