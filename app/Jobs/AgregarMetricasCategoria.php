<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AgregarMetricasCategoria implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // 1. Busca os últimos 90 dias
        $inicio = now()->subDays(90);

        // 2. Faz a agregação via DB::table
        $limites = \Illuminate\Support\Facades\DB::table('itens_pedido as item')
            ->join('pedidos as pedido', 'pedido.id', '=', 'item.pedido_id')
            ->join('produtos as produto', 'produto.id', '=', 'item.produto_id')
            ->where('pedido.data_venda', '>=', $inicio)
            ->selectRaw('
                pedido.loja_id,
                produto.categoria_id,
                COUNT(*) as qtd_vendas_periodo,
                AVG(item.preco_unitario_vendido) as preco_medio_vendido,
                AVG(
                    (item.preco_unitario_vendido - item.custo_unitario_no_momento) 
                    / NULLIF(item.preco_unitario_vendido, 0)
                ) as margem_realizada_media
            ')
            ->groupBy('pedido.loja_id', 'produto.categoria_id')
            ->get();

        // 3. Salva os resultados no banco gerando o UUID quando for uma loja nova
        foreach ($limites as $linha) {
            $metricaExiste = \Illuminate\Support\Facades\DB::table('metricas_categoria_loja')
                ->where('loja_id', $linha->loja_id)
                ->where('categoria_id', $linha->categoria_id)
                ->exists();

            $dadosAtualizacao = [
                'qtd_vendas_periodo' => $linha->qtd_vendas_periodo,
                'margem_realizada_media' => round((float) $linha->margem_realizada_media, 4),
                'volume_minimo_atingido' => $linha->qtd_vendas_periodo >= 1, // Limite para ativar a Camada 4
                'periodo_referencia' => now()->toDateString(),
                'data_calculo' => now(),
                'desatualizada' => false, // Reseta a flag para a IA
                'updated_at' => now(),
            ];

            if ($metricaExiste) {
                // Se já existe (Warm Start), apenas atualiza
                \Illuminate\Support\Facades\DB::table('metricas_categoria_loja')
                    ->where('loja_id', $linha->loja_id)
                    ->where('categoria_id', $linha->categoria_id)
                    ->update($dadosAtualizacao);
            } else {
                // Se não existe (Cold Start virando Warm), gera o UUID e insere
                $dadosAtualizacao['id'] = \Illuminate\Support\Str::uuid()->toString();
                $dadosAtualizacao['loja_id'] = $linha->loja_id;
                $dadosAtualizacao['categoria_id'] = $linha->categoria_id;
                $dadosAtualizacao['created_at'] = now();
                
                \Illuminate\Support\Facades\DB::table('metricas_categoria_loja')->insert($dadosAtualizacao);
            }
        }
    }
}