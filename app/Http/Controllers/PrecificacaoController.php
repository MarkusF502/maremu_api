<?php

namespace App\Http\Controllers;

use App\Models\LogsSugestaoIa;
use App\Models\Produto;
use App\Services\PrecificacaoPayloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        private readonly PrecificacaoPayloadService $payloadService
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
        ]);

        // ── Fase 5: chamada ao Gemini entrará aqui ────────────────────────
        // $cenarios = $this->geminiService->sugerirPrecos($payload, $systemPrompt);
        // $log->update(['cenarios_retornados' => $cenarios]);
        // return response()->json(['payload' => $payload, 'cenarios' => $cenarios, 'log_id' => $log->id]);
        // ─────────────────────────────────────────────────────────────────

        // Por enquanto (Fase 2), retorna o payload montado para validação
        return response()->json([
            'payload'           => $payload,
            'log_id'            => $log->id,
            'camada_4_ativa'    => !is_null($payload['camada_4']),
            'canais_disponiveis' => count($payload['camada_2']['canais']),
        ]);
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

        // Atualiza o log com a escolha
        $log->update([
            'cenario_escolhido'      => $request->cenario_escolhido,
            'preco_final_escolhido'  => $request->preco_final,
        ]);

        // Atualiza o preço e a origem no produto
        $log->produto->update([
            'preco_venda_atual' => $request->preco_final,
            'preco_origem'      => $request->cenario_escolhido === 'manual'
                                        ? 'manual'
                                        : $request->cenario_escolhido,
        ]);

        return response()->json([
            'message'     => 'Preço confirmado com sucesso.',
            'produto_id'  => $log->produto_id,
            'preco_final' => $request->preco_final,
        ]);
    }
}