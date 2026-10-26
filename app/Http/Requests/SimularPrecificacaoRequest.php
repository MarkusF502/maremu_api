<?php

namespace App\Http\Requests;

use App\Services\TaxaCanalPrecificacao;
use App\Support\MargemLiquida;
use Illuminate\Foundation\Http\FormRequest;

class SimularPrecificacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $loja = $this->user()->loja;
        $padroes = ['custo_base' => 50];
        if ($loja !== null) {
            $padroes += [
                'custo_fixo_mensal' => $loja->custo_fixo_mensal,
                'volume_vendas_esperado' => $loja->volume_vendas_esperado,
                'aliquota_efetiva' => $loja->aliquota_efetiva,
                'taxa_canal' => app(TaxaCanalPrecificacao::class)->obter($loja),
            ];
        }
        foreach ($padroes as $campo => $valor) {
            if (! array_key_exists($campo, $this->all())) {
                $this->merge([$campo => $valor]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'margem' => ['required', ...MargemLiquida::regras()],
            'custo_base' => ['required', 'numeric', 'gt:0'],
            'custo_fixo_mensal' => ['required', 'numeric', 'min:0'],
            'volume_vendas_esperado' => ['required', 'integer', 'min:1'],
            'aliquota_efetiva' => ['required', 'numeric', 'min:0', 'max:1'],
            'taxa_canal' => ['required', 'numeric', 'min:0', 'max:1'],
        ];
    }
}
