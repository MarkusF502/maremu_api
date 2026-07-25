<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaidaController extends Controller
{
    /**
     * Retorna o catálogo real da loja para uso no ponto de venda.
     */
    public function catalogo(Request $request): JsonResponse
    {
        $loja = $request->user()->loja()->firstOrFail();
        $busca = trim((string) $request->query('busca', ''));

        $produtos = $loja->produtos()
            ->with([
                'categoria:id,nome',
                'variantes:id,produto_id,tamanho,quantidade_estoque,estoque_minimo_alerta',
            ])
            ->whereIn('status', ['ativo', 'liquidacao'])
            ->whereNotNull('preco_venda_atual')
            ->where('preco_venda_atual', '>', 0)
            ->when($busca !== '', function ($query) use ($busca): void {
                $termo = '%'.mb_strtolower($busca).'%';

                $query->where(function ($query) use ($termo): void {
                    $query
                        ->whereRaw('LOWER(nome) LIKE ?', [$termo])
                        ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', [$termo]);
                });
            })
            ->orderBy('nome')
            ->get()
            ->map(function ($produto): array {
                $variantes = $produto->variantes
                    ->sortBy('tamanho', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values()
                    ->map(fn ($variante): array => [
                        'id' => $variante->id,
                        'tamanho' => $variante->tamanho,
                        'quantidade_estoque' => (int) $variante->quantidade_estoque,
                        'estoque_minimo_alerta' => (int) $variante->estoque_minimo_alerta,
                    ]);

                return [
                    'id' => $produto->id,
                    'nome' => $produto->nome,
                    'sku' => $produto->sku,
                    'categoria' => $produto->categoria?->nome ?? 'Sem categoria',
                    'status' => $produto->status,
                    'preco_venda_atual' => round((float) $produto->preco_venda_atual, 2),
                    'estoque_total' => (int) $variantes->sum('quantidade_estoque'),
                    'variantes' => $variantes,
                ];
            })
            ->values();

        return response()->json([
            'produtos' => $produtos,
        ]);
    }

    /**
     * Lista as saídas mais recentes registradas no banco da loja autenticada.
     */
    public function index(Request $request): JsonResponse
    {
        $loja = $request->user()->loja()->firstOrFail();
        $limite = min(max((int) $request->query('limit', 10), 1), 50);

        $pedidos = DB::table('pedidos')
            ->where('loja_id', $loja->id)
            ->orderByDesc('data_venda')
            ->orderByDesc('created_at')
            ->limit($limite)
            ->get();

        $itensPorPedido = collect();

        if ($pedidos->isNotEmpty()) {
            $itensPorPedido = DB::table('itens_pedido as item')
                ->join('produtos as produto', 'produto.id', '=', 'item.produto_id')
                ->leftJoin('variantes_produto as variante', 'variante.id', '=', 'item.variante_id')
                ->whereIn('item.pedido_id', $pedidos->pluck('id'))
                ->select([
                    'item.pedido_id',
                    'item.produto_id',
                    'item.variante_id',
                    'item.quantidade',
                    'item.preco_unitario_vendido',
                    'item.desconto_aplicado',
                    'produto.nome as produto',
                    'variante.tamanho',
                ])
                ->orderBy('item.created_at')
                ->get()
                ->groupBy('pedido_id');
        }

        $pedidosFormatados = $pedidos->map(function ($pedido) use ($itensPorPedido): array {
            $itens = collect($itensPorPedido->get($pedido->id, collect()))
                ->map(function ($item): array {
                    $preco = (float) $item->preco_unitario_vendido;
                    $desconto = (float) $item->desconto_aplicado;
                    $quantidade = (int) $item->quantidade;

                    return [
                        'produto_id' => $item->produto_id,
                        'variante_id' => $item->variante_id,
                        'produto' => $item->produto,
                        'tamanho' => $item->tamanho,
                        'quantidade' => $quantidade,
                        'preco_unitario' => round($preco, 2),
                        'desconto' => round($desconto, 2),
                        'total' => round(($preco * $quantidade) - $desconto, 2),
                    ];
                })
                ->values();

            $subtotal = round((float) $itens->sum(
                fn (array $item): float => $item['preco_unitario'] * $item['quantidade']
            ), 2);
            $desconto = round((float) $itens->sum('desconto'), 2);

            return [
                'id' => $pedido->id,
                'canal_venda' => $pedido->canal_venda,
                'forma_pagamento' => $pedido->forma_pagamento,
                'data_venda' => Carbon::parse($pedido->data_venda)->toIso8601String(),
                'subtotal' => $subtotal,
                'desconto' => $desconto,
                'valor_total' => round((float) $pedido->valor_total, 2),
                'quantidade_itens' => (int) $itens->sum('quantidade'),
                'itens' => $itens,
            ];
        });

        $inicioHoje = now()->startOfDay();
        $fimHoje = now()->endOfDay();

        $resumoHoje = DB::table('pedidos')
            ->where('loja_id', $loja->id)
            ->whereBetween('data_venda', [$inicioHoje, $fimHoje])
            ->selectRaw('COUNT(*) as quantidade, COALESCE(SUM(valor_total), 0) as faturamento')
            ->first();

        return response()->json([
            'resumo_hoje' => [
                'quantidade_vendas' => (int) ($resumoHoje->quantidade ?? 0),
                'faturamento' => round((float) ($resumoHoje->faturamento ?? 0), 2),
            ],
            'pedidos' => $pedidosFormatados,
        ]);
    }

    /**
     * Registra uma saída, cria pedido e itens e baixa o estoque com bloqueio transacional.
     */
    public function store(Request $request): JsonResponse
    {
        $loja = $request->user()->loja()->firstOrFail();

        $data = $request->validate([
            'canal_venda' => [
                'required',
                Rule::in(['loja_fisica', 'instagram_whatsapp', 'marketplace', 'outro']),
            ],
            'forma_pagamento' => ['required', 'string', 'max:50'],
            'desconto' => ['nullable', 'numeric', 'min:0'],
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.variante_id' => ['required', 'uuid', 'distinct'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1'],
        ]);

        $resultado = DB::transaction(function () use ($data, $loja): array {
            $itensRecebidos = collect($data['itens']);
            $idsVariantes = $itensRecebidos->pluck('variante_id')->values();

            // O bloqueio impede duas vendas simultâneas de utilizarem o mesmo estoque.
            $variantes = DB::table('variantes_produto')
                ->whereIn('id', $idsVariantes)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $produtos = DB::table('produtos')
                ->where('loja_id', $loja->id)
                ->whereIn('id', $variantes->pluck('produto_id')->unique())
                ->get()
                ->keyBy('id');

            $linhas = $itensRecebidos->map(function (array $item) use ($variantes, $produtos): array {
                $variante = $variantes->get($item['variante_id']);

                if (! $variante) {
                    throw ValidationException::withMessages([
                        'itens' => 'Uma das variações selecionadas não existe mais.',
                    ]);
                }

                $produto = $produtos->get($variante->produto_id);

                if (! $produto) {
                    throw ValidationException::withMessages([
                        'itens' => 'Uma das variações não pertence à sua loja.',
                    ]);
                }

                if (! in_array($produto->status, ['ativo', 'liquidacao'], true)) {
                    throw ValidationException::withMessages([
                        'itens' => "O produto {$produto->nome} está inativo.",
                    ]);
                }

                $quantidade = (int) $item['quantidade'];
                $estoqueAtual = (int) $variante->quantidade_estoque;

                if ($quantidade > $estoqueAtual) {
                    throw ValidationException::withMessages([
                        'itens' => sprintf(
                            'Estoque insuficiente para %s, tamanho %s. Disponível: %d.',
                            $produto->nome,
                            $variante->tamanho,
                            $estoqueAtual
                        ),
                    ]);
                }

                $preco = round((float) $produto->preco_venda_atual, 2);

                if ($preco <= 0) {
                    throw ValidationException::withMessages([
                        'itens' => "O produto {$produto->nome} não possui preço de venda válido.",
                    ]);
                }

                $custo = round(
                    (float) $produto->custo_aquisicao + (float) $produto->frete_entrada_unitario,
                    2
                );

                return [
                    'produto_id' => $produto->id,
                    'variante_id' => $variante->id,
                    'produto' => $produto->nome,
                    'tamanho' => $variante->tamanho,
                    'quantidade' => $quantidade,
                    'estoque_anterior' => $estoqueAtual,
                    'preco_unitario' => $preco,
                    'custo_unitario' => $custo,
                    'subtotal' => round($preco * $quantidade, 2),
                ];
            })->values();

            $subtotal = round((float) $linhas->sum('subtotal'), 2);
            $descontoTotal = round((float) ($data['desconto'] ?? 0), 2);

            if ($descontoTotal > $subtotal) {
                throw ValidationException::withMessages([
                    'desconto' => 'O desconto não pode ser maior que o subtotal da venda.',
                ]);
            }

            $valorTotal = round($subtotal - $descontoTotal, 2);
            $pedidoId = (string) Str::uuid();
            $agora = now();

            DB::table('pedidos')->insert([
                'id' => $pedidoId,
                'loja_id' => $loja->id,
                'canal_venda' => $data['canal_venda'],
                'valor_total' => $valorTotal,
                'forma_pagamento' => $data['forma_pagamento'],
                'data_venda' => $agora,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);

            $descontoRestante = $descontoTotal;
            $linhasParaRetorno = [];

            foreach ($linhas as $indice => $linha) {
                $ultimaLinha = $indice === $linhas->count() - 1;
                $descontoLinha = 0.0;

                if ($descontoTotal > 0) {
                    $descontoLinha = $ultimaLinha
                        ? $descontoRestante
                        : round($descontoTotal * ($linha['subtotal'] / $subtotal), 2);

                    $descontoLinha = min($descontoLinha, $descontoRestante, $linha['subtotal']);
                    $descontoRestante = round($descontoRestante - $descontoLinha, 2);
                }

                DB::table('itens_pedido')->insert([
                    'id' => (string) Str::uuid(),
                    'pedido_id' => $pedidoId,
                    'produto_id' => $linha['produto_id'],
                    'variante_id' => $linha['variante_id'],
                    'quantidade' => $linha['quantidade'],
                    'preco_unitario_vendido' => $linha['preco_unitario'],
                    'custo_unitario_no_momento' => $linha['custo_unitario'],
                    'desconto_aplicado' => $descontoLinha,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);

                DB::table('variantes_produto')
                    ->where('id', $linha['variante_id'])
                    ->update([
                        'quantidade_estoque' => $linha['estoque_anterior'] - $linha['quantidade'],
                        'updated_at' => $agora,
                    ]);

                $linhasParaRetorno[] = [
                    'produto' => $linha['produto'],
                    'tamanho' => $linha['tamanho'],
                    'quantidade' => $linha['quantidade'],
                    'preco_unitario' => $linha['preco_unitario'],
                    'desconto' => round($descontoLinha, 2),
                    'total' => round($linha['subtotal'] - $descontoLinha, 2),
                    'estoque_restante' => $linha['estoque_anterior'] - $linha['quantidade'],
                ];
            }

            return [
                'id' => $pedidoId,
                'canal_venda' => $data['canal_venda'],
                'forma_pagamento' => $data['forma_pagamento'],
                'data_venda' => $agora->toIso8601String(),
                'subtotal' => $subtotal,
                'desconto' => $descontoTotal,
                'valor_total' => $valorTotal,
                'quantidade_itens' => (int) $linhas->sum('quantidade'),
                'itens' => $linhasParaRetorno,
            ];
        }, 3);

        return response()->json([
            'message' => 'Venda finalizada e estoque atualizado com sucesso.',
            'pedido' => $resultado,
        ], 201);
    }
}
