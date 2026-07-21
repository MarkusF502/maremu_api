<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProdutoRequest;
use App\Http\Requests\UpdateProdutoRequest;
use App\Models\Produto;
use App\Models\VarianteProduto;
use App\Services\PricingEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProdutoController extends Controller
{
    public function __construct(private readonly PricingEngine $pricingEngine) {}

    public function index(Request $request): JsonResponse
    {
        $loja = $request->user()->loja()->firstOrFail();

        $produtos = $loja->produtos()
            ->with(['categoria', 'variantes'])
            ->when($request->filled('nome'), fn ($query) => $query->whereRaw('LOWER(nome) LIKE ?', ['%'.mb_strtolower($request->string('nome')->trim()->toString()).'%']))
            ->when($request->filled('categoria_id'), fn ($query) => $query->where('categoria_id', $request->input('categoria_id')))
            ->when($request->filled('status'), fn (Builder $query) => $this->applyStockStatusFilter($query, $request->input('status')))
            ->when($request->filled('preco_min'), fn ($query) => $query->where('preco_venda_atual', '>=', $request->input('preco_min')))
            ->when($request->filled('preco_max'), fn ($query) => $query->where('preco_venda_atual', '<=', $request->input('preco_max')))
            ->orderBy('nome')
            ->get();

        return response()->json([
            'produtos' => $produtos,
        ]);
    }

    public function store(StoreProdutoRequest $request): JsonResponse
    {
        $loja = $request->user()->loja()->with('canaisAtivos')->firstOrFail();
        $data = $request->validated();

        // 1. Chamar o PricingEngine para calcular os preços
        $taxaCanalPrincipal = $loja->canaisAtivos->max('taxa_percentual') ?? 0.0;

        $dadosPreco = $this->pricingEngine->calcularPreco(
            custoAquisicao: $data['custo_aquisicao'],
            freteEntradaUnitario: $data['frete_entrada_unitario'] ?? 0.0,
            aliquotaEfetiva: $loja->aliquota_efetiva,
            taxaCanal: $taxaCanalPrincipal,
            custoFixoMensal: $loja->custo_fixo_mensal,
            volumeVendasEsperado: $loja->volume_vendas_esperado,
            margemLucroDesejada: $loja->margem_lucro_desejada
        );

        $produto = DB::transaction(function () use ($data, $loja, $dadosPreco) {
            $produto = Produto::create([
                'loja_id' => $loja->id,
                'categoria_id' => $data['categoria_id'],
                'nome' => $data['nome'],
                'custo_aquisicao' => $data['custo_aquisicao'],
                'frete_entrada_unitario' => $data['frete_entrada_unitario'] ?? 0.0,
                'preco_piso' => $dadosPreco['preco_piso'],
                // Se o usuário não definir um preço, sugerimos o calculado pelo PricingEngine
                'preco_venda_atual' => $data['preco_venda_atual'] ?? $dadosPreco['preco_venda'],
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
        $loja = $request->user()->loja()->with('canaisAtivos')->firstOrFail(); // ← adiciona isso
        $data = $request->validated();

        $produto = DB::transaction(function () use ($produto, $data, $loja) {
            $taxaCanalPrincipal = $loja->canaisAtivos->max('taxa_percentual') ?? 0.0;

            $dadosPreco = $this->pricingEngine->calcularPreco(
                custoAquisicao: $data['custo_aquisicao'],
                freteEntradaUnitario: $data['frete_entrada_unitario'] ?? 0.0,
                aliquotaEfetiva: $loja->aliquota_efetiva,
                taxaCanal: $taxaCanalPrincipal,
                custoFixoMensal: $loja->custo_fixo_mensal,
                volumeVendasEsperado: $loja->volume_vendas_esperado,
                margemLucroDesejada: $loja->margem_lucro_desejada,
            );

            $produto->update([
                'categoria_id' => $data['categoria_id'],
                'nome' => $data['nome'],
                'custo_aquisicao' => $data['custo_aquisicao'],
                'frete_entrada_unitario' => $data['frete_entrada_unitario'] ?? 0.0,
                'preco_piso' => $dadosPreco['preco_piso'],
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

    private function applyStockStatusFilter(Builder $query, string $status): void
    {
        $estoqueTotal = '(SELECT COALESCE(SUM(quantidade_estoque), 0) FROM variantes_produto WHERE variantes_produto.produto_id = produtos.id)';
        $estoqueMinimo = '(SELECT COALESCE(SUM(estoque_minimo_alerta), 0) FROM variantes_produto WHERE variantes_produto.produto_id = produtos.id)';

        match ($status) {
            'em_estoque' => $query->whereRaw("{$estoqueTotal} > {$estoqueMinimo}"),
            'estoque_baixo' => $query->whereRaw("{$estoqueTotal} > 0")
                ->whereRaw("{$estoqueTotal} <= {$estoqueMinimo}"),
            'critico' => $query->whereRaw("{$estoqueTotal} <= 0"),
            default => null,
        };
    }
}
