<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Loja;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Camada3MercadoService
 *
 * Compara a loja com lojas semelhantes na mesma sub-categoria da taxonomia
 * fixa. Determinístico, sem IA: a camada 3 não entra no prompt de precificação.
 *
 * A comparação é sempre anônima e agregada. Cada loja comparável vira UM ponto
 * da amostra — uma loja com 40 produtos pesa igual a uma com 2 —, e a saída só
 * contém a distribuição desses pontos: nunca id, nome ou valor de uma loja.
 *
 * Os filtros são aplicados em cascata, do mais rígido para o mais frouxo:
 * mundo (real/demo) → posicionamento → faixa de faturamento, essa última só
 * quando há folga de amostra para pagar o estreitamento.
 */
class Camada3MercadoService
{
    /**
     * Idade máxima de um registro de métricas para entrar na comparação.
     * Acima disso o dado da outra loja é velho demais para servir de régua.
     */
    private const JANELA_MAXIMA_DIAS = 90;

    /**
     * Mínimo de lojas na amostra. Abaixo disso a distribuição não representa
     * mercado nenhum e ainda deixaria a loja individual quase identificável.
     */
    private const MINIMO_LOJAS = 5;

    /**
     * Acima desse número de lojas vale estreitar a comparação pela faixa de
     * faturamento: há amostra suficiente para perder algumas lojas e ganhar
     * lojas de porte parecido.
     */
    private const LOJAS_PARA_ESTREITAR = 10;

    public function __construct(private readonly OnboardingService $onboardingService) {}

    /**
     * Faixa de mercado da sub-categoria de uma categoria da loja.
     *
     * Ausência de dado não é erro: devolve `disponivel => false` com o motivo.
     *
     * @return array{
     *   disponivel: bool,
     *   motivo?: string,
     *   sub_categoria?: array{id: int, nome: string},
     *   n_lojas?: int,
     *   filtros_aplicados?: string[],
     *   preco?: array{p25: float, mediana: float, p75: float},
     *   margem?: array{p25: float, mediana: float, p75: float}|null,
     *   giro?: array{p25: float, mediana: float, p75: float}|null,
     * }
     */
    public function faixaDeMercado(Loja $loja, Categoria $categoria): array
    {
        $subCategoria = $categoria->subCategoria;

        // Categoria anterior à taxonomia, ou ainda não classificada pelo lojista.
        if ($subCategoria === null) {
            return ['disponivel' => false, 'motivo' => 'categoria_sem_classificacao'];
        }

        // "Outros" e afins reúnem produtos heterogêneos: comparar não diz nada.
        if (! $subCategoria->comparavel) {
            return ['disponivel' => false, 'motivo' => 'sub_categoria_nao_comparavel'];
        }

        $pontos = $this->pontosPorLoja($loja, $subCategoria->id);
        $filtros = ['posicionamento'];

        if ($pontos->count() > self::LOJAS_PARA_ESTREITAR) {
            $faixa = $this->onboardingService->faixaDoFaturamento((float) $loja->faturamento_medio_mensal);
            $estreitado = $pontos->where('faixa_faturamento', $faixa)->values();

            // Só estreita se o resultado continuar sendo uma amostra.
            if ($estreitado->count() >= self::MINIMO_LOJAS) {
                $pontos = $estreitado;
                $filtros[] = 'faixa_faturamento';
            }
        }

        if ($pontos->count() < self::MINIMO_LOJAS) {
            return ['disponivel' => false, 'motivo' => 'amostra_insuficiente'];
        }

        $giros = $this->valores($pontos, 'giro');

        return [
            'disponivel'    => true,
            'sub_categoria' => ['id' => $subCategoria->id, 'nome' => $subCategoria->nome],
            'n_lojas'       => $pontos->count(),
            'filtros_aplicados' => $filtros,
            'preco'         => $this->distribuicao($this->valores($pontos, 'preco'), 2),
            'margem'        => $this->distribuicao($this->valores($pontos, 'margem'), 4),
            // Giro é opcional: muitas lojas ainda não têm venda suficiente para
            // ter giro calculado, e uma amostra curta de giro não é régua.
            'giro'          => count($giros) >= self::MINIMO_LOJAS
                ? $this->distribuicao($giros, 2)
                : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // AMOSTRA
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Um ponto por loja comparável: preço, margem e giro daquela loja na
     * sub-categoria, mais a faixa de faturamento dela.
     *
     * @return Collection<int, array{preco: float, margem: float|null, giro: float|null, faixa_faturamento: string}>
     */
    private function pontosPorLoja(Loja $loja, int $subCategoriaId): Collection
    {
        $registros = DB::table('metricas_categoria_loja as m')
            ->join('categorias as c', 'c.id', '=', 'm.categoria_id')
            ->join('lojas as l', 'l.id', '=', 'm.loja_id')
            ->where('c.sub_categoria_id', $subCategoriaId)
            ->where('m.periodo_referencia', '>=', now()->subDays(self::JANELA_MAXIMA_DIAS)->toDateString())
            // Janela sem fotografia do catálogo não serve de referência de preço.
            ->whereNotNull('m.preco_mediano')
            ->where('m.n_produtos', '>', 0)
            // A loja não se compara consigo mesma.
            ->where('m.loja_id', '!=', $loja->id)
            // Loja real nunca vê dado de demo, e demo nunca vê dado real.
            // O cast protege o modelo recém-criado, que ainda não leu o default.
            ->where('l.is_demo', (bool) $loja->is_demo)
            ->where('l.posicionamento', $loja->posicionamento)
            ->orderBy('m.periodo_referencia')
            ->get([
                'm.loja_id',
                'm.categoria_id',
                'm.preco_mediano',
                'm.margem_mediana',
                'm.giro_medio_dias',
                'l.faturamento_medio_mensal',
            ]);

        return $registros
            ->groupBy('loja_id')
            ->map(function (Collection $daLoja): array {
                // Ordenado por período, keyBy guarda a última ocorrência de cada
                // categoria: sobra só a janela mais recente de cada uma.
                $maisRecentes = $daLoja->keyBy('categoria_id');

                return [
                    // Loja com mais de uma categoria na mesma sub-categoria:
                    // a mediana das categorias é o valor da loja, e ela continua
                    // valendo um ponto só.
                    'preco'  => (float) $this->mediana($maisRecentes->pluck('preco_mediano')),
                    'margem' => $this->mediana($maisRecentes->pluck('margem_mediana')),
                    'giro'   => $this->mediana($maisRecentes->pluck('giro_medio_dias')),
                    // Derivada do cadastro atual da loja, não de coluna gravada.
                    'faixa_faturamento' => $this->onboardingService->faixaDoFaturamento(
                        (float) $maisRecentes->first()->faturamento_medio_mensal
                    ),
                ];
            })
            ->values();
    }

    /**
     * Valores não nulos de uma medida, já ordenados.
     *
     * @param  Collection<int, array<string, mixed>> $pontos
     * @return float[]
     */
    private function valores(Collection $pontos, string $medida): array
    {
        $valores = $pontos->pluck($medida)->reject(fn ($valor) => $valor === null)->all();
        sort($valores);

        return $valores;
    }

    // ─────────────────────────────────────────────────────────────────────
    // ESTATÍSTICA
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Distribuição de uma medida: quartis e mediana, arredondados nas casas
     * da unidade (reais 2, margem 4, giro 2).
     *
     * @param  float[] $ordenados
     * @return array{p25: float, mediana: float, p75: float}|null
     */
    private function distribuicao(array $ordenados, int $casas): ?array
    {
        if ($ordenados === []) {
            return null;
        }

        return [
            'p25'     => round($this->percentil($ordenados, 0.25), $casas),
            'mediana' => round($this->percentil($ordenados, 0.50), $casas),
            'p75'     => round($this->percentil($ordenados, 0.75), $casas),
        ];
    }

    /**
     * Percentil por interpolação linear entre os dois valores vizinhos — o
     * mesmo resultado do PERCENTILE.INC do Excel, para o lojista poder
     * conferir a conta na planilha.
     *
     * @param  float[] $ordenados  valores já em ordem crescente
     */
    private function percentil(array $ordenados, float $p): float
    {
        $posicao = $p * (count($ordenados) - 1);
        $inferior = (int) floor($posicao);
        $fracao = $posicao - $inferior;

        if ($fracao === 0.0) {
            return $ordenados[$inferior];
        }

        return $ordenados[$inferior] + $fracao * ($ordenados[$inferior + 1] - $ordenados[$inferior]);
    }

    /**
     * Mediana dos valores não nulos, ou null se não houver nenhum.
     *
     * @param  Collection<int, mixed> $valores
     */
    private function mediana(Collection $valores): ?float
    {
        $lista = $valores
            ->reject(fn ($valor) => $valor === null)
            ->map(fn ($valor) => (float) $valor)
            ->sort()
            ->values()
            ->all();

        return $lista === [] ? null : $this->percentil($lista, 0.50);
    }
}
