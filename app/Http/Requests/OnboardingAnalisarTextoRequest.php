<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida a Tela 2 (texto dissertativo) + os dados factuais da Tela 1.
 * Usada na rota POST /api/loja/onboarding/analisar-texto
 */
class OnboardingAnalisarTextoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'texto_descritivo'   => ['required', 'string', 'min:50', 'max:5000'],
            'nome_loja'          => ['required', 'string', 'min:2', 'max:100'],
            'regime_tributario'  => ['required', 'in:simples_nacional,lucro_presumido,lucro_real'],
            'canais_marcados'    => ['required', 'array', 'min:1'],
            'canais_marcados.*'  => ['in:loja_fisica,instagram_whatsapp,marketplace'],
        ];
    }

    public function messages(): array
    {
        return [
            'texto_descritivo.required' => 'Descreva seu negócio para continuarmos.',
            'texto_descritivo.min'      => 'Conte um pouco mais sobre o seu negócio (mínimo 50 caracteres).',
            'texto_descritivo.max'      => 'Texto muito longo — resuma um pouco (máximo 5000 caracteres).',
            'nome_loja.required'        => 'O nome da loja é obrigatório.',
            'regime_tributario.required' => 'Selecione o regime tributário.',
            'regime_tributario.in'      => 'Regime tributário inválido.',
            'canais_marcados.required'  => 'Selecione ao menos um canal de venda.',
            'canais_marcados.min'       => 'Selecione ao menos um canal de venda.',
            'canais_marcados.*.in'      => 'Canal de venda inválido.',
        ];
    }
}
