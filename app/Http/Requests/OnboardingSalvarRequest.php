<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida os dados revisados pelo lojista na tela de configurações.
 * Usada na rota POST /api/loja/onboarding/salvar
 *
 * O lojista pode ter editado qualquer campo — a origem de cada campo
 * sensível (aliquota, custo_fixo) é rastreada via o campo *_origem.
 */
class OnboardingSalvarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Identificação
            'nome'                     => ['required', 'string', 'min:2', 'max:100'],
            'posicionamento'           => ['required', 'in:popular,medio,premium'],
            'regime_tributario'        => ['required', 'in:simples_nacional,lucro_presumido,lucro_real'],

            // Dados financeiros
            'faturamento_medio_mensal' => ['required', 'numeric', 'min:0'],
            'custo_fixo_mensal'        => ['required', 'numeric', 'min:0'],
            'custo_fixo_origem'        => ['required', 'in:estimado_pelo_sistema,confirmado_pelo_lojista,editado_pelo_lojista'],
            'margem_lucro_desejada'    => ['required', 'numeric', 'min:0.01', 'max:0.99'],
            'aliquota_efetiva'         => ['required', 'numeric', 'min:0.001', 'max:0.99'],
            'aliquota_origem'          => ['required', 'in:estimado_pelo_sistema,confirmado_pelo_lojista,editado_pelo_lojista'],
            'volume_vendas_esperado'   => ['required', 'integer', 'min:1'],

            // Canais
            'canais'                   => ['required', 'array', 'min:1'],
            'canais.*.canal'           => ['required', 'in:loja_fisica,instagram_whatsapp,marketplace'],
            'canais.*.taxa_percentual' => ['required', 'numeric', 'min:0', 'max:0.99'],
            'canais.*.taxa_origem'     => ['required', 'in:estimado_pelo_sistema,confirmado_pelo_lojista,editado_pelo_lojista'],
        ];
    }

    public function messages(): array
    {
        return [
            'margem_lucro_desejada.min'  => 'A margem de lucro deve ser maior que 0.',
            'margem_lucro_desejada.max'  => 'A margem de lucro não pode ser 99% ou mais.',
            'aliquota_efetiva.min'       => 'A alíquota deve ser maior que 0.',
            'volume_vendas_esperado.min' => 'O volume de vendas esperado deve ser ao menos 1.',
            'canais.min'                 => 'Ao menos um canal de venda é obrigatório.',
        ];
    }
}