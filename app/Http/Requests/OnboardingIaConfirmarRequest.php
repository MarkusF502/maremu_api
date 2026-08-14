<?php

namespace App\Http\Requests;

use App\Services\OnboardingGuardrail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida a confirmação da Tela 3 — os valores estimados pela IA, já
 * revisados (e possivelmente editados) pelo lojista.
 * Usada na rota POST /api/loja/onboarding/confirmar-ia
 *
 * As mesmas faixas de OnboardingGuardrail::RANGES são reaproveitadas aqui
 * como regras de validação Laravel (SPEC §6.3 passo 1).
 */
class OnboardingIaConfirmarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $r = OnboardingGuardrail::RANGES;

        return [
            'log_id'                   => ['required', 'uuid', 'exists:logs_onboarding_ia,id'],
            'nome'                     => ['required', 'string', 'min:2', 'max:100'],
            'posicionamento'           => ['required', 'in:popular,medio,premium'],
            'regime_tributario'        => ['required', 'in:simples_nacional,lucro_presumido,lucro_real'],
            'faturamento_medio_mensal' => ['required', 'numeric', "min:{$r['faturamento_medio_mensal']['min']}", "max:{$r['faturamento_medio_mensal']['max']}"],
            'custo_fixo_mensal'        => ['required', 'numeric', "min:{$r['custo_fixo_mensal']['min']}", "max:{$r['custo_fixo_mensal']['max']}"],
            'margem_lucro_desejada'    => ['required', 'numeric', "min:{$r['margem_lucro_desejada']['min']}", "max:{$r['margem_lucro_desejada']['max']}"],
            'volume_vendas_esperado'   => ['required', 'integer', "min:{$r['volume_vendas_esperado']['min']}", "max:{$r['volume_vendas_esperado']['max']}"],
            'canais'                   => ['required', 'array', 'min:1'],
            'canais.*'                 => ['in:loja_fisica,instagram_whatsapp,marketplace'],
        ];
    }
}
