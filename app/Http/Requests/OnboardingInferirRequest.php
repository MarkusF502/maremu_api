<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida as respostas das 4 perguntas do onboarding.
 * Usada na rota POST /api/loja/onboarding/inferir
 */
class OnboardingInferirRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome'              => ['required', 'string', 'min:2', 'max:100'],
            'faixa_faturamento' => ['required', 'in:ate_10k,de_10k_a_30k,de_30k_a_80k,acima_80k'],
            'posicionamento'    => ['required', 'in:popular,medio,premium'],
            'regime'            => ['required', 'in:simples_nacional,lucro_presumido,lucro_real'],
            'canais'            => ['required', 'array', 'min:1'],
            'canais.*'          => ['in:loja_fisica,instagram_whatsapp,marketplace'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required'              => 'O nome da loja é obrigatório.',
            'faixa_faturamento.required' => 'Selecione a faixa de faturamento.',
            'faixa_faturamento.in'       => 'Faixa de faturamento inválida.',
            'posicionamento.required'    => 'Selecione o posicionamento da loja.',
            'posicionamento.in'          => 'Posicionamento inválido.',
            'regime.required'            => 'Selecione o regime tributário.',
            'regime.in'                  => 'Regime tributário inválido.',
            'canais.required'            => 'Selecione ao menos um canal de venda.',
            'canais.min'                 => 'Selecione ao menos um canal de venda.',
            'canais.*.in'                => 'Canal de venda inválido.',
        ];
    }
}