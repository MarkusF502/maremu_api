<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Services\Camada3MercadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MercadoController extends Controller
{
    public function __construct(private readonly Camada3MercadoService $mercadoService) {}

    /**
     * Faixa de mercado da sub-categoria de uma categoria da loja (camada 3).
     *
     * Rota: GET /api/mercado/faixa?categoria_id={uuid}
     *
     * Ausência de dado não é erro: responde 200 com `disponivel => false` e o
     * motivo, para o frontend mostrar a explicação em vez de uma tela de erro.
     */
    public function faixa(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'categoria_id' => ['required', 'uuid'],
        ], [
            'categoria_id.required' => 'Informe a categoria.',
            'categoria_id.uuid'     => 'Categoria inválida.',
        ]);

        $loja = $request->user()->loja()->firstOrFail();

        // Categoria de outra loja não existe para esta: 404.
        $categoria = Categoria::where('id', $dados['categoria_id'])
            ->where('loja_id', $loja->id)
            ->firstOrFail();

        return response()->json($this->mercadoService->faixaDeMercado($loja, $categoria));
    }
}
