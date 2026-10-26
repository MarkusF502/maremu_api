<?php

namespace App\Support;

/** Lucro limpo como fração do preço de venda, após todos os custos e taxas. */
final class MargemLiquida
{
    public const RANGE = ['min' => 0.01, 'max' => 0.60];

    public static function regras(float $escala = 1): array
    {
        return ['numeric', 'min:'.(self::RANGE['min'] * $escala), 'max:'.(self::RANGE['max'] * $escala)];
    }
}
