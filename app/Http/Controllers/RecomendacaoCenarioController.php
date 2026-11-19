<?php

namespace App\Http\Controllers;

use App\Models\Produto;
use App\Services\RecomendacaoCenarioDados;
use App\Services\RecomendacaoCenarioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/produtos/{produto}/recomendacao-cenario
 *
 * Só consulta: não grava em logs_recomendacao_cenario (quem grava é o
 * POST /precificacao/sugerir, quando a recomendação é de fato exibida).
 */
class RecomendacaoCenarioController extends Controller
{
    public function show(
        Request $request,
        string $produtoId,
        RecomendacaoCenarioDados $dados,
        RecomendacaoCenarioService $servico,
    ): JsonResponse {
        // Produto de outra loja responde 404, como se não existisse.
        $produto = Produto::with(['loja', 'categoria', 'variantes'])
            ->where('id', $produtoId)
            ->where('loja_id', $request->user()->loja->id)
            ->firstOrFail();

        return response()->json($servico->paraResposta($dados->avaliarProduto($produto)));
    }
}
