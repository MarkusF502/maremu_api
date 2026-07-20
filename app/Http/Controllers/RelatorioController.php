<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RelatorioController extends Controller
{
    public function index(): JsonResponse
    {
        $loja = request()->user()->loja()->firstOrFail();

        $produtos = $loja->produtos()
            ->with([
                'categoria:id,nome',
                'variantes:id,produto_id,tamanho,quantidade_estoque,estoque_minimo_alerta',
            ])
            ->get();

        $dadosProdutos = $produtos->map(function ($produto): array {
            $estoque = (int) $produto->variantes->sum('quantidade_estoque');
            $custo = (float) $produto->custo_aquisicao + (float) $produto->frete_entrada_unitario;
            $venda = (float) ($produto->preco_venda_atual ?? 0);
            $lucroUnitario = $venda - $custo;

            return [
                'id' => $produto->id,
                'produto' => $produto->nome,
                'categoria' => $produto->categoria?->nome ?? 'Sem categoria',
                'categoria_id' => $produto->categoria_id,
                'estoque' => $estoque,
                'custo' => round($custo, 2),
                'venda' => round($venda, 2),
                'lucro_unitario' => round($lucroUnitario, 2),
                // Mantém o cálculo visual da referência: lucro em relação ao custo (markup).
                'margem_percentual' => $custo > 0
                    ? round(($lucroUnitario / $custo) * 100, 1)
                    : 0,
                'valor_estoque' => round($venda * $estoque, 2),
                'lucro_potencial' => round($lucroUnitario * $estoque, 2),
            ];
        });

        $totalUnidades = (int) $dadosProdutos->sum('estoque');
        $liquidezEstoque = round((float) $dadosProdutos->sum('valor_estoque'), 2);
        $lucroPotencialTotal = round((float) $dadosProdutos->sum('lucro_potencial'), 2);
        $lucroMedioPeca = $totalUnidades > 0
            ? round($lucroPotencialTotal / $totalUnidades, 2)
            : 0;

        $alertas = $produtos
            ->flatMap(function ($produto): Collection {
                return $produto->variantes
                    ->filter(fn ($variante) =>
                        (int) $variante->quantidade_estoque <= (int) $variante->estoque_minimo_alerta
                    )
                    ->map(function ($variante) use ($produto): array {
                        $quantidade = (int) $variante->quantidade_estoque;

                        return [
                            'produto' => $produto->nome,
                            'tamanho' => $variante->tamanho,
                            'quantidade' => $quantidade,
                            'estoque_minimo' => (int) $variante->estoque_minimo_alerta,
                            'mensagem' => sprintf(
                                '%s - Tamanho %s está com %d %s',
                                $produto->nome,
                                $variante->tamanho,
                                $quantidade,
                                $quantidade === 1 ? 'unidade' : 'unidades'
                            ),
                        ];
                    });
            })
            ->sortBy('quantidade')
            ->values();

        $estoquePorCategoria = $dadosProdutos
            ->groupBy('categoria')
            ->map(function (Collection $itens, string $categoria) use ($totalUnidades): array {
                $quantidade = (int) $itens->sum('estoque');

                return [
                    'categoria' => $categoria,
                    'quantidade' => $quantidade,
                    'percentual' => $totalUnidades > 0
                        ? round(($quantidade / $totalUnidades) * 100, 1)
                        : 0,
                ];
            })
            ->sortByDesc('quantidade')
            ->values();

        $curvaAbc = $this->montarCurvaAbc($dadosProdutos);

        $produtosMaiorLucro = $dadosProdutos
            ->filter(fn (array $produto) => $produto['venda'] > 0)
            ->sortByDesc('lucro_unitario')
            ->take(5)
            ->values()
            ->map(fn (array $produto): array => [
                'produto' => $produto['produto'],
                'categoria' => $produto['categoria'],
                'custo' => $produto['custo'],
                'venda' => $produto['venda'],
                'lucro_unitario' => $produto['lucro_unitario'],
                'margem_percentual' => $produto['margem_percentual'],
                'estoque' => $produto['estoque'],
            ]);

        $metricasVendas = $this->calcularMetricasVendas($loja->id);
        $lucroPorCategoria = $metricasVendas['lucro_por_categoria'];
        $fonteLucroCategoria = 'vendas_realizadas';

        // Enquanto ainda não houver venda registrada, o gráfico continua útil usando
        // o lucro potencial calculado exclusivamente a partir do estoque real cadastrado.
        if ($lucroPorCategoria->isEmpty()) {
            $lucroPorCategoria = $this->calcularLucroPotencialPorCategoria($dadosProdutos);
            $fonteLucroCategoria = 'estoque_potencial';
        }

        return response()->json([
            'resumo' => [
                'liquidez_estoque' => $liquidezEstoque,
                'total_unidades_estoque' => $totalUnidades,
                'lucro_medio_peca' => $lucroMedioPeca,
                'upt' => $metricasVendas['upt'],
                'ticket_medio' => $metricasVendas['ticket_medio'],
                'total_pedidos' => $metricasVendas['total_pedidos'],
                'fonte_lucro_categoria' => $fonteLucroCategoria,
            ],
            'alertas_criticos' => $alertas,
            'curva_abc' => $curvaAbc,
            'produtos_maior_lucro' => $produtosMaiorLucro,
            'estoque_por_categoria' => $estoquePorCategoria,
            'lucro_por_categoria' => $lucroPorCategoria,
        ]);
    }

    private function montarCurvaAbc(Collection $dadosProdutos): Collection
    {
        $ordenados = $dadosProdutos
            ->filter(fn (array $produto) => $produto['valor_estoque'] > 0)
            ->sortByDesc('valor_estoque')
            ->values();

        if ($ordenados->count() > 8) {
            $principais = $ordenados->take(7);
            $restantes = $ordenados->slice(7);

            $ordenados = $principais->push([
                'produto' => sprintf('Outros (%d)', $restantes->count()),
                'categoria' => 'Outros',
                'valor_estoque' => round((float) $restantes->sum('valor_estoque'), 2),
            ]);
        }

        $total = (float) $ordenados->sum('valor_estoque');
        $acumulado = 0.0;

        return $ordenados->map(function (array $produto) use ($total, &$acumulado): array {
            $percentual = $total > 0
                ? ((float) $produto['valor_estoque'] / $total) * 100
                : 0;
            $antes = $acumulado;
            $acumulado += $percentual;

            $classe = $antes < 80 ? 'A' : ($antes < 95 ? 'B' : 'C');

            return [
                'produto' => $produto['produto'],
                'categoria' => $produto['categoria'],
                'valor_estoque' => round((float) $produto['valor_estoque'], 2),
                'percentual' => round($percentual, 1),
                'percentual_acumulado' => round(min($acumulado, 100), 1),
                'classe' => $classe,
            ];
        })->values();
    }

    private function calcularMetricasVendas(string $lojaId): array
    {
        if (! Schema::hasTable('pedidos') || ! Schema::hasTable('itens_pedido')) {
            return [
                'upt' => 0,
                'ticket_medio' => 0,
                'total_pedidos' => 0,
                'lucro_por_categoria' => collect(),
            ];
        }

        $totalPedidos = (int) DB::table('pedidos')
            ->where('loja_id', $lojaId)
            ->count();

        $ticketMedio = $totalPedidos > 0
            ? round((float) DB::table('pedidos')->where('loja_id', $lojaId)->avg('valor_total'), 2)
            : 0;

        $totalItens = (int) DB::table('itens_pedido as item')
            ->join('pedidos as pedido', 'pedido.id', '=', 'item.pedido_id')
            ->where('pedido.loja_id', $lojaId)
            ->sum('item.quantidade');

        $upt = $totalPedidos > 0 ? round($totalItens / $totalPedidos, 2) : 0;

        $lucros = DB::table('itens_pedido as item')
            ->join('pedidos as pedido', 'pedido.id', '=', 'item.pedido_id')
            ->join('produtos as produto', 'produto.id', '=', 'item.produto_id')
            ->join('categorias as categoria', 'categoria.id', '=', 'produto.categoria_id')
            ->where('pedido.loja_id', $lojaId)
            ->selectRaw(
                'categoria.nome as categoria, '
                .'SUM((item.preco_unitario_vendido - item.custo_unitario_no_momento) * item.quantidade - item.desconto_aplicado) as lucro'
            )
            ->groupBy('categoria.id', 'categoria.nome')
            ->get()
            ->map(fn ($item): array => [
                'categoria' => $item->categoria,
                'lucro' => round((float) $item->lucro, 2),
            ])
            ->filter(fn (array $item) => $item['lucro'] > 0)
            ->sortByDesc('lucro')
            ->values();

        $totalLucro = (float) $lucros->sum('lucro');
        $lucros = $lucros->map(fn (array $item): array => [
            ...$item,
            'percentual' => $totalLucro > 0
                ? round(($item['lucro'] / $totalLucro) * 100, 1)
                : 0,
        ]);

        return [
            'upt' => $upt,
            'ticket_medio' => $ticketMedio,
            'total_pedidos' => $totalPedidos,
            'lucro_por_categoria' => $lucros,
        ];
    }

    private function calcularLucroPotencialPorCategoria(Collection $dadosProdutos): Collection
    {
        $categorias = $dadosProdutos
            ->groupBy('categoria')
            ->map(fn (Collection $itens, string $categoria): array => [
                'categoria' => $categoria,
                'lucro' => round(max(0, (float) $itens->sum('lucro_potencial')), 2),
            ])
            ->filter(fn (array $item) => $item['lucro'] > 0)
            ->sortByDesc('lucro')
            ->values();

        $total = (float) $categorias->sum('lucro');

        return $categorias->map(fn (array $item): array => [
            ...$item,
            'percentual' => $total > 0
                ? round(($item['lucro'] / $total) * 100, 1)
                : 0,
        ]);
    }
}
