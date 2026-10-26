<?php

namespace App\Http\Controllers;

use App\Http\Requests\SimularPrecificacaoRequest;
use App\Services\PricingEngine;
use Illuminate\Http\JsonResponse;

class SimularPrecificacaoController extends Controller
{
    public function __invoke(SimularPrecificacaoRequest $request, PricingEngine $engine): JsonResponse
    {
        $dados = $request->validated();
        return response()->json($engine->simular(
            custoBase: (float) $dados['custo_base'],
            custoFixoMensal: (float) $dados['custo_fixo_mensal'],
            volumeVendasEsperado: (int) $dados['volume_vendas_esperado'],
            aliquotaEfetiva: (float) $dados['aliquota_efetiva'],
            taxaCanal: (float) $dados['taxa_canal'],
            margem: (float) $dados['margem'],
        ));
    }
}
