<?php

namespace App\Http\Controllers;

use App\Models\LogsSugestaoIa;
use App\Models\Produto;
use App\Services\PrecificacaoIaInterface;
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
        private readonly PrecificacaoIaInterface $iaService,
        private readonly \App\Services\PricingEngine $pricingEngine,
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

        // Salva o log antes de qualquer chamada externa
        // Na Fase 5, os cenarios_retornados serão preenchidos aqui
        $log = LogsSugestaoIa::create([
            'produto_id'      => $produto->id,
            'payload_enviado' => $payload,
            'provedor_ia'     => $this->iaService->identificador(),
        ]);

        try {
            $resultado = $this->iaService->sugerirCenarios($payload);
        } catch (\RuntimeException $e) {
            Log::error('Falha ao obter sugestão de preço via ' . $this->iaService->identificador(), [
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
        $cenarios = $this->calcularPrecosDosCenarios($resultado['cenarios'], $produto);

        $log->update(['cenarios_retornados' => $cenarios]);

        return response()->json([
            'log_id'   => $log->id,
            'cenarios' => $cenarios,
        ]);
    }

    /**
     * Converte a margem_lucro_percentual de cada cenário (vinda da IA) em
     * preco_sugerido, via PricingEngine. A IA nunca calcula o preço final —
     * ver PrecificacaoIaInterface::sugerirCenarios().
     *
     * @param  array<array{id: string, tipo: string, margem_lucro_percentual: float, explicacao: string}>  $cenariosIa
     * @return array<array{id: string, tipo: string, margem_lucro_percentual: float, explicacao: string, preco_sugerido: float, preco_piso_referencia: float}>
     */
    private function calcularPrecosDosCenarios(array $cenariosIa, Produto $produto): array
    {
        $loja         = $produto->loja;
        $taxaCanal    = $this->taxaCanalAplicavel($loja);

        foreach ($cenariosIa as &$cenario) {
            $margem = (float) $cenario['margem_lucro_percentual'];

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