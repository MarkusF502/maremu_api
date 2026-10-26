<?php

namespace App\Services;

use App\Models\Loja;

final class TaxaCanalPrecificacao
{
    /** Maior taxa ativa: mesma premissa conservadora em todos os cálculos. */
    public function obter(Loja $loja): ?float
    {
        $taxas = $loja->canaisAtivos->pluck('taxa_percentual')->map(fn ($taxa) => (float) $taxa);
        return $taxas->isEmpty() ? null : $taxas->max();
    }
}
