<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Giro de um produto: dias por unidade vendida no horizonte de referência.
 *
 * Fonte única da conta usada pelo job AgregarMetricasCategoria (que tira a
 * mediana por categoria) e pela recomendação de cenário (que compara o
 * produto com essa mediana). As duas precisam da mesma fórmula, senão a
 * razão entre elas mede a diferença de conta, e não de giro.
 *
 * Giro = dias observados ÷ unidades vendidas no horizonte, onde dias
 * observados é o tempo desde o cadastro, contando o próprio dia do cadastro,
 * limitado ao horizonte e nunca menor que 1.
 */
final class GiroProduto
{
    /** Horizonte padrão, em dias (config metricas.horizonte_dias). */
    public static function horizonteDias(): int
    {
        return (int) config('metricas.horizonte_dias', 90);
    }

    /**
     * Primeiro dia do horizonte que termina em $hoje, inclusive. As vendas
     * contam de início->startOfDay() até hoje->endOfDay().
     */
    public static function inicioDoHorizonte(CarbonImmutable $hoje, int $horizonteDias): CarbonImmutable
    {
        return $hoje->startOfDay()->subDays($horizonteDias - 1);
    }

    public static function diasObservados(DateTimeInterface|string|null $dataCadastro, CarbonImmutable $hoje, int $horizonteDias): int
    {
        $hoje = $hoje->startOfDay();
        $cadastro = $dataCadastro
            ? CarbonImmutable::parse($dataCadastro)->startOfDay()
            : $hoje;

        return max(1, min($horizonteDias, (int) $cadastro->diffInDays($hoje) + 1));
    }

    /**
     * Giro em dias por unidade, sem arredondamento; null se não vendeu.
     */
    public static function dias(DateTimeInterface|string|null $dataCadastro, int $unidadesVendidas, CarbonImmutable $hoje, int $horizonteDias): ?float
    {
        if ($unidadesVendidas <= 0) {
            return null;
        }

        return self::diasObservados($dataCadastro, $hoje, $horizonteDias) / $unidadesVendidas;
    }
}
