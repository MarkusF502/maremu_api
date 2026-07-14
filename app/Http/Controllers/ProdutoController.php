<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProdutoRequest;
use App\Http\Requests\UpdateProdutoRequest;
use App\Models\Produto;
use App\Models\VarianteProduto;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProdutoController extends Controller
{
    public function index(): JsonResponse
    {
        $loja = request()->user()->loja()->firstOrFail();

        $produtos = $loja->produtos()
            ->with(['categoria', 'variantes'])
            ->orderBy('nome')
            ->get();

        return response()->json([
            'produtos' => $produtos,
        ]);
    }

    public function store(StoreProdutoRequest $request): JsonResponse
    {
        $loja = $request->user()->loja()->firstOrFail();
        $data = $request->validated();

        $produto = DB::transaction(function () use ($data, $loja) {
            $produto = Produto::create([
                'loja_id' => $loja->id,
                'categoria_id' => $data['categoria_id'],
                'nome' => $data['nome'],
                'custo_aquisicao' => $data['custo_aquisicao'],
                'preco_venda_atual' => $data['preco_venda_atual'] ?? null,
                'status' => $data['status'] ?? 'ativo',
            ]);

            foreach ($data['variantes'] as $variante) {
                VarianteProduto::create([
                    'produto_id' => $produto->id,
                    'tamanho' => $variante['tamanho'],
                    'quantidade_estoque' => $variante['quantidade_estoque'],
                    'estoque_minimo_alerta' => $variante['estoque_minimo_alerta'] ?? 3,
                ]);
            }

            return $produto->load(['categoria', 'variantes']);
        });

        return response()->json($produto->fresh(['categoria', 'variantes']), 201);
    }

    public function show(Produto $produto): JsonResponse
    {
        $this->assertProdutoDaLoja($produto);

        return response()->json([
            'produto' => $produto->load(['categoria', 'variantes']),
        ]);
    }

    public function update(UpdateProdutoRequest $request, Produto $produto): JsonResponse
    {
        $this->assertProdutoDaLoja($produto);

        $data = $request->validated();

        $produto = DB::transaction(function () use ($produto, $data) {
            $produto->update([
                'categoria_id' => $data['categoria_id'],
                'nome' => $data['nome'],
                'custo_aquisicao' => $data['custo_aquisicao'],
                'preco_venda_atual' => $data['preco_venda_atual'] ?? null,
                'status' => $data['status'] ?? $produto->status,
            ]);

            $produto->variantes()->delete();

            foreach ($data['variantes'] as $variante) {
                VarianteProduto::create([
                    'produto_id' => $produto->id,
                    'tamanho' => $variante['tamanho'],
                    'quantidade_estoque' => $variante['quantidade_estoque'],
                    'estoque_minimo_alerta' => $variante['estoque_minimo_alerta'] ?? 3,
                ]);
            }

            return $produto->load(['categoria', 'variantes']);
        });

        return response()->json($produto->fresh(['categoria', 'variantes']));
    }

    public function destroy(Produto $produto): JsonResponse
    {
        $this->assertProdutoDaLoja($produto);

        $produto->delete();

        return response()->json([
            'message' => 'Produto excluído com sucesso.',
        ]);
    }

    private function assertProdutoDaLoja(Produto $produto): void
    {
        $loja = request()->user()->loja()->firstOrFail();

        abort_unless($produto->loja_id === $loja->id, 404);
    }
}