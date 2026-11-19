<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * RecomendacaoCenarioService
 *
 * Indica qual dos três cenários de preço (liquidação, venda ideal, alta
 * demanda) combina com a situação de venda de um produto em estoque. Regra
 * determinística, sem IA e sem banco: recebe os sinais já medidos (ver
 * RecomendacaoCenarioDados) e devolve sempre a mesma saída para a mesma
 * entrada. O sistema recomenda; o lojista escolhe. Nenhum preço é aplicado.
 *
 * Os limiares vêm de config/recomendacao.php (heurística inicial, sem
 * calibração empírica).
 */
class RecomendacaoCenarioService
{
    public const LIQUIDACAO = 'liquidacao';

    public const IDEAL = 'ideal';

    public const ALTA_DEMANDA = 'alta_demanda';

    public const HISTORICO_PROPRIO = 'historico_proprio';

    public const LOJAS_SIMILARES = 'lojas_similares';

    public const SEM_RECOMENDACAO = 'sem_recomendacao';

    public const SEM_ESTOQUE = 'sem_estoque';

    public const SEM_REFERENCIA = 'sem_referencia';

    public const DADOS_INSUFICIENTES = 'dados_insuficientes';

    /** @var array<string, mixed> */
    private array $parametros;

    /**
     * @param  array<string, mixed>|null  $parametros  null usa config('recomendacao')
     */
    public function __construct(?array $parametros = null)
    {
        $this->parametros = $parametros ?? (array) config('recomendacao');
    }

    // ─────────────────────────────────────────────────────────────────────
    // GIRO DE REFERÊNCIA
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Giro de referência da categoria, em cascata:
     * 1. historico_proprio — o registro MAIS RECENTE de metricas_categoria_loja
     *    da categoria, se tiver no máximo 90 dias, giro não nulo e ao menos 5
     *    produtos da categoria vendendo nos últimos 90 dias;
     * 2. lojas_similares — a mediana de giro da camada 3, se disponível;
     * 3. null — sem referência (origem sem_recomendacao).
     *
     * @param  object|array<string, mixed>|null  $metricaMaisRecente  com periodo_referencia e giro_medio_dias
     * @param  int  $produtosComVenda  produtos da categoria com venda nos últimos 90 dias
     * @param  array<string, mixed>|null  $faixaCamada3  saída de Camada3MercadoService::faixaDeMercado()
     * @return array{giro_dias: float, origem: string}|null
     */
    public function referencia(object|array|null $metricaMaisRecente, int $produtosComVenda, ?array $faixaCamada3, CarbonInterface $hoje): ?array
    {
        $metrica = $metricaMaisRecente === null ? null : (object) $metricaMaisRecente;

        if ($metrica !== null
            && $metrica->giro_medio_dias !== null
            && $this->metricaRecente($metrica->periodo_referencia, $hoje)
            && $produtosComVenda >= (int) $this->parametros['minimo_produtos_com_venda']
        ) {
            return ['giro_dias' => round((float) $metrica->giro_medio_dias, 2), 'origem' => self::HISTORICO_PROPRIO];
        }

        $giroMercado = ($faixaCamada3['disponivel'] ?? false) ? ($faixaCamada3['giro']['mediana'] ?? null) : null;

        if ($giroMercado !== null && (float) $giroMercado > 0) {
            return ['giro_dias' => round((float) $giroMercado, 2), 'origem' => self::LOJAS_SIMILARES];
        }

        return null;
    }

    private function metricaRecente(mixed $periodoReferencia, CarbonInterface $hoje): bool
    {
        if ($periodoReferencia === null) {
            return false;
        }

        $periodo = CarbonImmutable::parse($periodoReferencia)->startOfDay();
        $hoje = CarbonImmutable::parse($hoje)->startOfDay();

        // Registro do futuro não acontece no fluxo normal; não serve de régua.
        if ($periodo->greaterThan($hoje)) {
            return false;
        }

        return $periodo->diffInDays($hoje) <= (int) $this->parametros['idade_maxima_metrica_dias'];
    }

    // ─────────────────────────────────────────────────────────────────────
    // REGRAS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Aplica as regras, nesta ordem:
     * 1. estoque zero → sem recomendação (sem_estoque): esgotar é sinal de
     *    demanda, não de encalhe;
     * 2. sem giro de referência → sem recomendação (sem_referencia);
     * 3. dias sem venda ≥ 2 × referência → liquidação;
     * 4. menos de 3 unidades em 90 dias → sem recomendação (dados_insuficientes);
     * 5. razão = giro do produto ÷ referência: ≥ 1,5 liquidação; ≤ 0,6 alta
     *    demanda; senão venda ideal.
     *
     * A avaliação carrega sinais, origem e versão mesmo quando não há
     * recomendação: é o que vai para logs_recomendacao_cenario.
     *
     * @param  array{estoque: int|float, unidades_vendidas_90d: int, giro_produto_dias: float|null, dias_sem_venda: int, nunca_vendeu?: bool}  $sinais
     * @param  array{giro_dias: float, origem: string}|null  $referencia
     * @return array{
     *   disponivel: bool,
     *   cenario_recomendado: string|null,
     *   origem_recomendacao: string,
     *   motivo: string|null,
     *   motivo_indisponivel: string|null,
     *   sinais: array{dias_sem_venda: int, unidades_vendidas_90d: int, giro_produto_dias: float|null, giro_referencia_dias: float|null, razao_giro_produto: float|null},
     *   versao_regras: string,
     * }
     */
    public function avaliar(array $sinais, ?array $referencia): array
    {
        $diasSemVenda = (int) $sinais['dias_sem_venda'];
        $unidades = (int) $sinais['unidades_vendidas_90d'];
        $minimoUnidades = (int) $this->parametros['minimo_unidades_90d'];
        $giroReferencia = $referencia['giro_dias'] ?? null;
        $origem = $referencia['origem'] ?? self::SEM_RECOMENDACAO;

        // Com poucas vendas o giro do produto não é confiável: nem sai no sinal.
        $giroProduto = $unidades >= $minimoUnidades && $sinais['giro_produto_dias'] !== null
            ? round((float) $sinais['giro_produto_dias'], 2)
            : null;

        $saida = [
            'dias_sem_venda' => $diasSemVenda,
            'unidades_vendidas_90d' => $unidades,
            'giro_produto_dias' => $giroProduto,
            'giro_referencia_dias' => $giroReferencia,
            'razao_giro_produto' => null,
        ];

        if ((float) $sinais['estoque'] <= 0) {
            return $this->indisponivel(self::SEM_ESTOQUE, $origem, $saida);
        }

        if ($giroReferencia === null || $giroReferencia <= 0) {
            return $this->indisponivel(self::SEM_REFERENCIA, self::SEM_RECOMENDACAO, $saida);
        }

        if ($diasSemVenda >= (float) $this->parametros['fator_dias_sem_venda'] * $giroReferencia) {
            $motivo = ($sinais['nunca_vendeu'] ?? false)
                ? sprintf('Este produto nunca vendeu e está há %s em estoque; nesta categoria, %s uma peça a cada %s.',
                    $this->dias($diasSemVenda), $this->quem($origem), $this->dias($giroReferencia))
                : sprintf('Este produto está há %s sem venda; nesta categoria, %s uma peça a cada %s.',
                    $this->dias($diasSemVenda), $this->quem($origem), $this->dias($giroReferencia));

            return $this->recomendado(self::LIQUIDACAO, $origem, $motivo, $saida);
        }

        if ($giroProduto === null) {
            return $this->indisponivel(self::DADOS_INSUFICIENTES, $origem, $saida);
        }

        // 4 casas: a comparação com os limiares não depende de ruído de ponto flutuante.
        $razao = round((float) $sinais['giro_produto_dias'] / $giroReferencia, 4);
        $saida['razao_giro_produto'] = $razao;

        $comparacao = sprintf('Este produto vende uma peça a cada %s; nesta categoria, %s uma peça a cada %s',
            $this->dias($giroProduto), $this->quem($origem), $this->dias($giroReferencia));

        if ($razao >= (float) $this->parametros['razao_liquidacao']) {
            return $this->recomendado(self::LIQUIDACAO, $origem, $comparacao.'. Ele está girando mais devagar que a categoria.', $saida);
        }

        if ($razao <= (float) $this->parametros['razao_alta_demanda']) {
            return $this->recomendado(self::ALTA_DEMANDA, $origem, $comparacao.'. Ele está saindo mais rápido que a categoria.', $saida);
        }

        return $this->recomendado(self::IDEAL, $origem, $comparacao.'. Ele está no ritmo da categoria.', $saida);
    }

    /**
     * Formato público (GET recomendacao-cenario e resposta de sugerir).
     *
     * @param  array<string, mixed>  $avaliacao  saída de avaliar()
     * @return array<string, mixed>
     */
    public function paraResposta(array $avaliacao): array
    {
        if (! $avaliacao['disponivel']) {
            return ['disponivel' => false, 'motivo_indisponivel' => $avaliacao['motivo_indisponivel']];
        }

        return [
            'disponivel' => true,
            'cenario_recomendado' => $avaliacao['cenario_recomendado'],
            'origem_recomendacao' => $avaliacao['origem_recomendacao'],
            'motivo' => $avaliacao['motivo'],
            'sinais' => $avaliacao['sinais'],
            'versao_regras' => $avaliacao['versao_regras'],
        ];
    }

    public function versaoRegras(): string
    {
        return (string) $this->parametros['versao_regras'];
    }

    // ─────────────────────────────────────────────────────────────────────
    // APOIO
    // ─────────────────────────────────────────────────────────────────────

    private function recomendado(string $cenario, string $origem, string $motivo, array $sinais): array
    {
        return [
            'disponivel' => true,
            'cenario_recomendado' => $cenario,
            'origem_recomendacao' => $origem,
            'motivo' => $motivo,
            'motivo_indisponivel' => null,
            'sinais' => $sinais,
            'versao_regras' => $this->versaoRegras(),
        ];
    }

    private function indisponivel(string $motivoIndisponivel, string $origem, array $sinais): array
    {
        return [
            'disponivel' => false,
            'cenario_recomendado' => null,
            'origem_recomendacao' => $origem,
            'motivo' => null,
            'motivo_indisponivel' => $motivoIndisponivel,
            'sinais' => $sinais,
            'versao_regras' => $this->versaoRegras(),
        ];
    }

    /** "sua loja vende" ou "lojas parecidas com a sua vendem". */
    private function quem(string $origem): string
    {
        return $origem === self::LOJAS_SIMILARES
            ? 'lojas parecidas com a sua vendem'
            : 'sua loja vende';
    }

    /** 18 → "18 dias"; 4.25 → "4,3 dias"; 1 → "1 dia". */
    private function dias(float|int $valor): string
    {
        $arredondado = round((float) $valor, 1);
        $texto = number_format($arredondado, $arredondado == floor($arredondado) ? 0 : 1, ',', '.');

        return $texto.($arredondado == 1.0 ? ' dia' : ' dias');
    }
}
