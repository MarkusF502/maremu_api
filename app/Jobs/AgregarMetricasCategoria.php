<?php

namespace App\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agrega as métricas de cada loja por categoria, um registro por janela.
 *
 * Cada execução atualiza a janela aberta de cada loja e categoria; quando a
 * janela completa `janela_dias`, ela é marcada como fechada e a execução
 * seguinte abre outra. Janelas fechadas nunca são alteradas nem apagadas:
 * são o histórico usado pela tendência temporal e pela camada 3.
 *
 * Dois horizontes convivem no mesmo registro:
 * - métricas de referência (giro, margens, volume): últimos `horizonte_dias`
 *   até o fim da janela, para não oscilar em lojas de pouco movimento;
 * - vendas da janela (unidades e unidades por dia): só o intervalo da janela.
 *
 * Todas as categorias com produto entram, inclusive as sem venda, para que
 * um registro antigo não continue valendo como se fosse atual.
 */
class AgregarMetricasCategoria implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TABELA = 'metricas_categoria_loja';

    public function handle(): void
    {
        $hoje = CarbonImmutable::now()->startOfDay();
        $horizonteDias = (int) config('metricas.horizonte_dias', 90);
        $inicioHorizonte = $hoje->subDays($horizonteDias - 1);

        $produtos = $this->carregarProdutos();
        if ($produtos->isEmpty()) {
            return;
        }

        $vendasHorizonte = $this->vendasPorProduto($inicioHorizonte, $hoje);

        foreach ($produtos->groupBy(fn ($p) => $p->loja_id.'|'.$p->categoria_id) as $grupo) {
            $lojaId = $grupo->first()->loja_id;
            $categoriaId = $grupo->first()->categoria_id;

            $referencia = $this->metricasDeReferencia($grupo, $vendasHorizonte, $hoje, $horizonteDias);
            $catalogo = $this->fotografiaDoCatalogo($grupo);

            $aberta = $this->janelaAberta($lojaId, $categoriaId, $hoje, $referencia['giro_medio_dias']);
            if ($aberta === null) {
                continue; // a janela anterior fechou hoje; a próxima abre amanhã
            }

            $inicio = CarbonImmutable::parse($aberta['periodo_inicio'])->startOfDay();
            $diasDecorridos = (int) $inicio->diffInDays($hoje) + 1;
            $unidadesJanela = $this->unidadesVendidas($lojaId, $categoriaId, $inicio, $hoje);

            $dados = array_merge($referencia, $catalogo, [
                'periodo_referencia' => $hoje->toDateString(),
                'periodo_inicio' => $inicio->toDateString(),
                'janela_dias' => $aberta['janela_dias'],
                'fechada' => $diasDecorridos >= $aberta['janela_dias'],
                'unidades_vendidas_janela' => $unidadesJanela,
                'vendas_por_dia' => round($unidadesJanela / $diasDecorridos, 4),
                'desatualizada' => false,
                'data_calculo' => now(),
                'updated_at' => now(),
            ]);

            if ($aberta['id'] !== null) {
                DB::table(self::TABELA)->where('id', $aberta['id'])->update($dados);
            } else {
                DB::table(self::TABELA)->insert(array_merge($dados, [
                    'id' => (string) Str::uuid(),
                    'loja_id' => $lojaId,
                    'categoria_id' => $categoriaId,
                    'created_at' => now(),
                ]));
            }
        }
    }

    /**
     * Produtos com o estoque atual somado das variantes.
     */
    private function carregarProdutos(): Collection
    {
        $estoque = DB::table('variantes_produto')
            ->selectRaw('produto_id, SUM(quantidade_estoque) as estoque')
            ->groupBy('produto_id');

        return DB::table('produtos as produto')
            ->leftJoinSub($estoque, 'e', 'e.produto_id', '=', 'produto.id')
            ->select([
                'produto.id',
                'produto.loja_id',
                'produto.categoria_id',
                'produto.custo_aquisicao',
                'produto.frete_entrada_unitario',
                'produto.preco_venda_atual',
                'produto.data_cadastro',
                DB::raw('COALESCE(e.estoque, 0) as estoque'),
            ])
            ->get();
    }

    /**
     * Unidades, receita líquida de desconto e custo por produto no intervalo.
     * desconto_aplicado é o desconto total da linha, não por unidade.
     */
    private function vendasPorProduto(CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        return DB::table('itens_pedido as item')
            ->join('pedidos as pedido', 'pedido.id', '=', 'item.pedido_id')
            ->whereBetween('pedido.data_venda', [$inicio->startOfDay(), $fim->endOfDay()])
            ->selectRaw('
                item.produto_id,
                SUM(item.quantidade) as unidades,
                SUM(item.preco_unitario_vendido * item.quantidade - item.desconto_aplicado) as receita,
                SUM(item.custo_unitario_no_momento * item.quantidade) as custo
            ')
            ->groupBy('item.produto_id')
            ->get()
            ->keyBy('produto_id');
    }

    private function unidadesVendidas(string $lojaId, string $categoriaId, CarbonImmutable $inicio, CarbonImmutable $fim): int
    {
        return (int) DB::table('itens_pedido as item')
            ->join('pedidos as pedido', 'pedido.id', '=', 'item.pedido_id')
            ->join('produtos as produto', 'produto.id', '=', 'item.produto_id')
            ->where('produto.loja_id', $lojaId)
            ->where('produto.categoria_id', $categoriaId)
            ->whereBetween('pedido.data_venda', [$inicio->startOfDay(), $fim->endOfDay()])
            ->sum('item.quantidade');
    }

    /**
     * Giro, margens e volume no horizonte de referência.
     *
     * Giro de um produto = dias observados ÷ unidades vendidas, onde dias
     * observados é o tempo desde o cadastro, limitado ao horizonte. O giro da
     * categoria é a mediana entre os produtos que venderam. Limitações
     * conhecidas: reposição de estoque não é rastreada, e produto sem venda
     * fica fora da mediana (quem o detecta é o sinal de dias sem venda).
     *
     * Margem realizada = (receita líquida de desconto − custo) ÷ receita,
     * ponderada por unidade. Margem planejada usa os mesmos itens vendidos,
     * mas ao preço de tabela atual do produto, de modo que a razão entre as
     * duas mede o quanto o desconto e a remarcação corroeram a margem.
     */
    private function metricasDeReferencia(Collection $produtos, Collection $vendas, CarbonImmutable $hoje, int $horizonteDias): array
    {
        $giros = [];
        $unidades = 0;
        $receita = 0.0;
        $custo = 0.0;
        $receitaTabela = 0.0;
        $custoTabela = 0.0;

        foreach ($produtos as $produto) {
            $venda = $vendas->get($produto->id);
            if ($venda === null || (int) $venda->unidades <= 0) {
                continue;
            }

            $qtd = (int) $venda->unidades;
            $unidades += $qtd;
            $receita += (float) $venda->receita;
            $custo += (float) $venda->custo;

            $precoTabela = (float) $produto->preco_venda_atual;
            if ($precoTabela > 0) {
                $receitaTabela += $precoTabela * $qtd;
                $custoTabela += (float) $venda->custo;
            }

            $cadastro = $produto->data_cadastro
                ? CarbonImmutable::parse($produto->data_cadastro)->startOfDay()
                : $hoje;
            $diasObservados = max(1, min($horizonteDias, (int) $cadastro->diffInDays($hoje) + 1));
            $giros[] = $diasObservados / $qtd;
        }

        return [
            'giro_medio_dias' => $giros === [] ? null : round($this->mediana($giros), 2),
            'margem_realizada_media' => $receita > 0 ? round(($receita - $custo) / $receita, 4) : null,
            'margem_planejada_media' => $receitaTabela > 0 ? round(($receitaTabela - $custoTabela) / $receitaTabela, 4) : null,
            'qtd_vendas_periodo' => $unidades,
            'volume_minimo_atingido' => $unidades >= (int) config('metricas.volume_minimo_unidades', 1),
        ];
    }

    /**
     * Preço e margem de tabela dos produtos com estoque no momento do cálculo.
     * Como não existe histórico de estoque, este é o único registro do saldo
     * no fechamento de cada janela.
     */
    private function fotografiaDoCatalogo(Collection $produtos): array
    {
        $precos = [];
        $margens = [];
        $estoqueTotal = 0;

        foreach ($produtos as $produto) {
            $estoque = (int) $produto->estoque;
            $preco = (float) $produto->preco_venda_atual;
            if ($estoque <= 0 || $preco <= 0) {
                continue;
            }

            $estoqueTotal += $estoque;
            $precos[] = $preco;
            $custoBase = (float) $produto->custo_aquisicao + (float) $produto->frete_entrada_unitario;
            $margens[] = ($preco - $custoBase) / $preco;
        }

        return [
            'preco_mediano' => $precos === [] ? null : round($this->mediana($precos), 2),
            'margem_mediana' => $margens === [] ? null : round($this->mediana($margens), 4),
            'n_produtos' => count($precos),
            'estoque_total' => $estoqueTotal,
        ];
    }

    /**
     * Devolve a janela a atualizar: a aberta, uma nova, ou null quando a
     * anterior fechou hoje. O tamanho da janela é fixado na abertura.
     *
     * @return array{id: ?string, periodo_inicio: string, janela_dias: int}|null
     */
    private function janelaAberta(string $lojaId, string $categoriaId, CarbonImmutable $hoje, ?float $giro): ?array
    {
        $base = fn () => DB::table(self::TABELA)
            ->where('loja_id', $lojaId)
            ->where('categoria_id', $categoriaId);

        $aberta = $base()->whereNotNull('periodo_inicio')->where('fechada', false)
            ->orderByDesc('periodo_inicio')->first();
        if ($aberta !== null) {
            return [
                'id' => $aberta->id,
                'periodo_inicio' => (string) $aberta->periodo_inicio,
                'janela_dias' => (int) $aberta->janela_dias,
            ];
        }

        $ultimaFechada = $base()->where('fechada', true)->orderByDesc('periodo_referencia')->first();
        $inicio = $hoje;
        if ($ultimaFechada !== null) {
            $inicio = CarbonImmutable::parse($ultimaFechada->periodo_referencia)->startOfDay()->addDay();
            if ($inicio->greaterThan($hoje)) {
                return null;
            }
        }

        // Registro do formato antigo (sem janela) gravado hoje: é reaproveitado,
        // porque a chave única é loja + categoria + periodo_referencia.
        $legadoDeHoje = $base()->whereNull('periodo_inicio')
            ->whereDate('periodo_referencia', $hoje->toDateString())->first();

        return [
            'id' => $legadoDeHoje?->id,
            'periodo_inicio' => $inicio->toDateString(),
            'janela_dias' => $this->tamanhoDaJanela($giro),
        ];
    }

    private function tamanhoDaJanela(?float $giro): int
    {
        $min = (int) config('metricas.janela_min_dias', 7);
        $max = (int) config('metricas.janela_max_dias', 30);

        if ($giro === null) {
            return $max;
        }

        $dias = (int) round($giro * (float) config('metricas.janela_fator_giro', 0.25));

        return max($min, min($max, $dias));
    }

    /**
     * @param  array<int, float|int>  $valores
     */
    private function mediana(array $valores): float
    {
        sort($valores);
        $n = count($valores);
        $meio = intdiv($n, 2);

        return $n % 2 === 1
            ? (float) $valores[$meio]
            : ($valores[$meio - 1] + $valores[$meio]) / 2;
    }
}
