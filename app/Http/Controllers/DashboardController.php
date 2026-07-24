<?php

// app/Http/Controllers/DashboardController.php
namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $loja  = request()->user()->loja()->with('canaisAtivos')->firstOrFail();
        $lojaId = $loja->id;
        $hoje  = Carbon::today();
        $semanaPassada = Carbon::now()->subDays(6)->startOfDay();

        // ── Resumo do dia ────────────────────────────────────────────────
        $resumoDia = DB::table('pedidos')
            ->join('itens_pedido', 'pedidos.id', '=', 'itens_pedido.pedido_id')
            ->where('pedidos.loja_id', $lojaId)
            ->whereDate('pedidos.data_venda', $hoje)
            ->selectRaw('
                COALESCE(SUM(itens_pedido.preco_unitario_vendido * itens_pedido.quantidade), 0) as faturamento,
                COALESCE(SUM(itens_pedido.quantidade), 0)                                       as pecas_vendidas,
                COALESCE(SUM(
                    (itens_pedido.preco_unitario_vendido - itens_pedido.custo_unitario_no_momento)
                    * itens_pedido.quantidade
                ), 0) as lucro_bruto,
                COUNT(DISTINCT pedidos.id) as total_pedidos
            ')
            ->first();

        $ticketMedio = $resumoDia->total_pedidos > 0
            ? $resumoDia->faturamento / $resumoDia->total_pedidos
            : 0;

        // ── Tendência 7 dias ─────────────────────────────────────────────
        $tendencia = DB::table('pedidos')
            ->join('itens_pedido', 'pedidos.id', '=', 'itens_pedido.pedido_id')
            ->where('pedidos.loja_id', $lojaId)
            ->where('pedidos.data_venda', '>=', $semanaPassada)
            ->selectRaw('
                DATE(pedidos.data_venda) as data,
                COALESCE(SUM(itens_pedido.preco_unitario_vendido * itens_pedido.quantidade), 0) as faturamento,
                COALESCE(SUM(
                    (itens_pedido.preco_unitario_vendido - itens_pedido.custo_unitario_no_momento)
                    * itens_pedido.quantidade
                ), 0) as lucro
            ')
            ->groupBy('data')
            ->orderBy('data')
            ->get();

        // Garante os 7 dias mesmo sem vendas (preenche com zero)
        $dias = collect();
        for ($i = 6; $i >= 0; $i--) {
            $data = Carbon::today()->subDays($i)->toDateString();
            $encontrado = $tendencia->firstWhere('data', $data);
            $dias->push([
                'data'        => $data,
                'label'       => Carbon::parse($data)->locale('pt_BR')->isoFormat('ddd'),
                'faturamento' => $encontrado ? round($encontrado->faturamento, 2) : 0,
                'lucro'       => $encontrado ? round($encontrado->lucro, 2) : 0,
            ]);
        }

        // ── Top 3 semana ─────────────────────────────────────────────────
        $topProdutos = DB::table('itens_pedido')
            ->join('pedidos', 'pedidos.id', '=', 'itens_pedido.pedido_id')
            ->join('produtos', 'produtos.id', '=', 'itens_pedido.produto_id')
            ->where('pedidos.loja_id', $lojaId)
            ->where('pedidos.data_venda', '>=', $semanaPassada)
            ->selectRaw('
                produtos.nome,
                SUM(itens_pedido.quantidade) as total_vendas
            ')
            ->groupBy('produtos.id', 'produtos.nome')
            ->orderByDesc('total_vendas')
            ->limit(3)
            ->get();

        // ── Alertas de estoque crítico ────────────────────────────────────
        $alertasEstoque = DB::table('variantes_produto')
            ->join('produtos', 'produtos.id', '=', 'variantes_produto.produto_id')
            ->where('produtos.loja_id', $lojaId)
            ->whereColumn('variantes_produto.quantidade_estoque', '<=', 'variantes_produto.estoque_minimo_alerta')
            ->where('produtos.status', 'ativo')
            ->select(
                'produtos.nome as produto_nome',
                'variantes_produto.tamanho',
                'variantes_produto.quantidade_estoque'
            )
            ->orderBy('variantes_produto.quantidade_estoque')
            ->limit(4)
            ->get();

        // ── Últimas transações ────────────────────────────────────────────
        $ultimasTransacoes = DB::table('itens_pedido')
            ->join('pedidos', 'pedidos.id', '=', 'itens_pedido.pedido_id')
            ->join('produtos', 'produtos.id', '=', 'itens_pedido.produto_id')
            ->where('pedidos.loja_id', $lojaId)
            ->select(
                'produtos.nome',
                'itens_pedido.quantidade',
                'itens_pedido.preco_unitario_vendido',
                'pedidos.data_venda'
            )
            ->orderByDesc('pedidos.data_venda')
            ->limit(5)
            ->get();

        return response()->json([
            'resumo_dia' => [
                'faturamento'   => round($resumoDia->faturamento, 2),
                'lucro_bruto'   => round($resumoDia->lucro_bruto, 2),
                'pecas_vendidas'=> (int) $resumoDia->pecas_vendidas,
                'ticket_medio'  => round($ticketMedio, 2),
            ],
            'tendencia_7dias'   => $dias,
            'top_produtos_semana' => $topProdutos,
            'alertas_estoque'   => $alertasEstoque,
            'ultimas_transacoes'=> $ultimasTransacoes,
        ]);
    }
}