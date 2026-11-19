<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Loja;
use App\Models\Produto;
use App\Support\GiroProduto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mede os sinais da recomendação de cenário e chama o serviço puro.
 *
 * Tudo em consultas agrupadas por loja, para servir a listagem sem uma
 * consulta por produto:
 * - estoque: das variantes já carregadas, ou uma soma agrupada;
 * - vendas: unidades nos 90 dias e data da última venda, por produto;
 * - produtos com venda por categoria, nos 90 dias;
 * - métricas: o registro mais recente de cada categoria;
 * - camada 3: só para as categorias sem referência própria, uma vez cada.
 */
class RecomendacaoCenarioDados
{
    public function __construct(
        private readonly RecomendacaoCenarioService $servico,
        private readonly Camada3MercadoService $camada3,
    ) {}

    /**
     * Avaliação completa (ver RecomendacaoCenarioService::avaliar) por produto.
     *
     * @param  Collection<int, Produto>  $produtos  todos da mesma loja
     * @return array<string, array<string, mixed>> indexado pelo id do produto
     */
    public function avaliar(Loja $loja, Collection $produtos, ?CarbonImmutable $hoje = null): array
    {
        if ($produtos->isEmpty()) {
            return [];
        }

        $hoje = ($hoje ?? CarbonImmutable::now())->startOfDay();
        $horizonte = GiroProduto::horizonteDias();
        $inicio = GiroProduto::inicioDoHorizonte($hoje, $horizonte);

        $estoques = $this->estoques($produtos);
        $vendas = $this->vendasPorProduto($loja, $inicio, $hoje);
        $referencias = $this->referenciasPorCategoria($loja, $produtos, $inicio, $hoje);

        $avaliacoes = [];
        foreach ($produtos as $produto) {
            $venda = $vendas->get($produto->id);
            $unidades = (int) ($venda->unidades_90d ?? 0);
            $ultimaVenda = $venda?->ultima_venda;

            $desde = CarbonImmutable::parse($ultimaVenda ?? $produto->data_cadastro ?? $hoje)->startOfDay();

            $sinais = [
                'estoque' => $estoques[$produto->id] ?? 0,
                'unidades_vendidas_90d' => $unidades,
                'giro_produto_dias' => GiroProduto::dias($produto->data_cadastro, $unidades, $hoje, $horizonte),
                'dias_sem_venda' => $desde->greaterThan($hoje) ? 0 : (int) $desde->diffInDays($hoje),
                'nunca_vendeu' => $ultimaVenda === null,
            ];

            $avaliacoes[$produto->id] = $this->servico->avaliar($sinais, $referencias[$produto->categoria_id] ?? null);
        }

        return $avaliacoes;
    }

    /**
     * @return array<string, mixed>
     */
    public function avaliarProduto(Produto $produto, ?CarbonImmutable $hoje = null): array
    {
        $produto->loadMissing(['loja', 'categoria', 'variantes']);

        return $this->avaliar($produto->loja, collect([$produto]), $hoje)[$produto->id];
    }

    /**
     * Recomendação resumida da listagem: cenário e se difere do cenário em
     * uso, ou null quando não há recomendação.
     *
     * @param  Collection<int, Produto>  $produtos
     * @return array<string, array{cenario_recomendado: string, difere_do_atual: bool}|null>
     */
    public function resumoParaListagem(Loja $loja, Collection $produtos, ?CarbonImmutable $hoje = null): array
    {
        $avaliacoes = $this->avaliar($loja, $produtos, $hoje);
        $tiposEmUso = $this->tiposEmUso($produtos);

        $resumo = [];
        foreach ($produtos as $produto) {
            $avaliacao = $avaliacoes[$produto->id];

            if (! $avaliacao['disponivel']) {
                $resumo[$produto->id] = null;

                continue;
            }

            $emUso = $tiposEmUso[$produto->id] ?? null;

            $resumo[$produto->id] = [
                'cenario_recomendado' => $avaliacao['cenario_recomendado'],
                'difere_do_atual' => $emUso === null || $emUso !== $avaliacao['cenario_recomendado'],
            ];
        }

        return $resumo;
    }

    /**
     * Tipo (liquidacao, ideal, alta_demanda) do cenário em uso por produto.
     *
     * preco_origem guarda só o id (ia_cenario_N), e o vínculo id ↔ tipo não é
     * fixo: é a IA que devolve os dois. O tipo é lido do log mais recente do
     * produto em que o lojista escolheu aquele cenário_N. Produto manual, sem
     * origem ou sem log fica de fora (o chamador trata como "difere").
     *
     * @param  Collection<int, Produto>  $produtos
     * @return array<string, string>
     */
    public function tiposEmUso(Collection $produtos): array
    {
        $porId = $produtos
            ->filter(fn (Produto $p) => is_string($p->preco_origem) && str_starts_with($p->preco_origem, 'ia_'))
            ->mapWithKeys(fn (Produto $p) => [$p->id => substr($p->preco_origem, 3)]);

        if ($porId->isEmpty()) {
            return [];
        }

        $logs = DB::table('logs_sugestao_ia')
            ->whereIn('produto_id', $porId->keys()->all())
            ->whereIn('cenario_escolhido', ['cenario_1', 'cenario_2', 'cenario_3'])
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->get(['produto_id', 'cenario_escolhido', 'cenarios_retornados']);

        $tipos = [];
        foreach ($logs as $log) {
            if (isset($tipos[$log->produto_id]) || $porId[$log->produto_id] !== $log->cenario_escolhido) {
                continue;
            }

            $tipo = self::tipoDoCenario($log->cenarios_retornados, $log->cenario_escolhido);
            if ($tipo !== null) {
                $tipos[$log->produto_id] = $tipo;
            }
        }

        return $tipos;
    }

    /**
     * Tipo de um cenário_N dentro dos cenários de uma rodada da IA.
     *
     * @param  string|array<int, array<string, mixed>>|null  $cenariosRetornados
     */
    public static function tipoDoCenario(string|array|null $cenariosRetornados, ?string $cenarioId): ?string
    {
        if ($cenarioId === null || $cenariosRetornados === null) {
            return null;
        }

        $cenarios = is_string($cenariosRetornados) ? json_decode($cenariosRetornados, true) : $cenariosRetornados;

        foreach ((array) $cenarios as $cenario) {
            if (($cenario['id'] ?? null) === $cenarioId) {
                return $cenario['tipo'] ?? null;
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // CONSULTAS AGRUPADAS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  Collection<int, Produto>  $produtos
     * @return array<string, int>
     */
    private function estoques(Collection $produtos): array
    {
        $semVariantes = $produtos->reject(fn (Produto $p) => $p->relationLoaded('variantes'));

        $estoques = [];
        foreach ($produtos as $produto) {
            if ($produto->relationLoaded('variantes')) {
                $estoques[$produto->id] = (int) $produto->variantes->sum('quantidade_estoque');
            }
        }

        if ($semVariantes->isNotEmpty()) {
            $somas = DB::table('variantes_produto')
                ->whereIn('produto_id', $semVariantes->pluck('id')->all())
                ->groupBy('produto_id')
                ->selectRaw('produto_id, SUM(quantidade_estoque) as estoque')
                ->pluck('estoque', 'produto_id');

            foreach ($semVariantes as $produto) {
                $estoques[$produto->id] = (int) ($somas[$produto->id] ?? 0);
            }
        }

        return $estoques;
    }

    /**
     * Unidades nos 90 dias (mesmo intervalo do job) e última venda, de todo
     * produto da loja que já vendeu.
     */
    private function vendasPorProduto(Loja $loja, CarbonImmutable $inicio, CarbonImmutable $hoje): Collection
    {
        return DB::table('itens_pedido as item')
            ->join('pedidos as pedido', 'pedido.id', '=', 'item.pedido_id')
            ->join('produtos as produto', 'produto.id', '=', 'item.produto_id')
            ->where('produto.loja_id', $loja->id)
            ->groupBy('item.produto_id')
            ->selectRaw(
                'item.produto_id,
                SUM(CASE WHEN pedido.data_venda BETWEEN ? AND ? THEN item.quantidade ELSE 0 END) as unidades_90d,
                MAX(pedido.data_venda) as ultima_venda',
                [$inicio->startOfDay()->toDateTimeString(), $hoje->endOfDay()->toDateTimeString()]
            )
            ->get()
            ->keyBy('produto_id');
    }

    /**
     * Giro de referência de cada categoria presente em $produtos, calculado
     * uma vez por categoria.
     *
     * @param  Collection<int, Produto>  $produtos
     * @return array<string, array{giro_dias: float, origem: string}|null>
     */
    private function referenciasPorCategoria(Loja $loja, Collection $produtos, CarbonImmutable $inicio, CarbonImmutable $hoje): array
    {
        $categoriaIds = $produtos->pluck('categoria_id')->unique()->values()->all();

        $metricas = DB::table('metricas_categoria_loja')
            ->where('loja_id', $loja->id)
            ->whereIn('categoria_id', $categoriaIds)
            ->orderByDesc('periodo_referencia')
            ->get(['categoria_id', 'periodo_referencia', 'giro_medio_dias'])
            ->unique('categoria_id')
            ->keyBy('categoria_id');

        $produtosComVenda = DB::table('itens_pedido as item')
            ->join('pedidos as pedido', 'pedido.id', '=', 'item.pedido_id')
            ->join('produtos as produto', 'produto.id', '=', 'item.produto_id')
            ->where('produto.loja_id', $loja->id)
            ->whereIn('produto.categoria_id', $categoriaIds)
            ->whereBetween('pedido.data_venda', [$inicio->startOfDay(), $hoje->endOfDay()])
            ->groupBy('produto.categoria_id')
            ->selectRaw('produto.categoria_id, COUNT(DISTINCT item.produto_id) as produtos')
            ->pluck('produtos', 'categoria_id');

        $categorias = $produtos
            ->map(fn (Produto $p) => $p->relationLoaded('categoria') ? $p->categoria : null)
            ->filter()
            ->keyBy('id');

        $referencias = [];
        foreach ($categoriaIds as $categoriaId) {
            $metrica = $metricas->get($categoriaId);
            $comVenda = (int) ($produtosComVenda[$categoriaId] ?? 0);

            // A própria referência basta: a camada 3 nem é consultada.
            $propria = $this->servico->referencia($metrica, $comVenda, null, $hoje);
            if ($propria !== null) {
                $referencias[$categoriaId] = $propria;

                continue;
            }

            $categoria = $categorias->get($categoriaId) ?? Categoria::find($categoriaId);
            $faixa = $categoria ? $this->camada3->faixaDeMercado($loja, $categoria) : null;

            $referencias[$categoriaId] = $this->servico->referencia($metrica, $comVenda, $faixa, $hoje);
        }

        return $referencias;
    }
}
