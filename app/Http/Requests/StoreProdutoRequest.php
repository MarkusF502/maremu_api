<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProdutoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $lojaId = $this->user()?->loja?->id;

        return [
            'nome' => ['required', 'string', 'min:2', 'max:150'],
            'categoria_id' => [
                'required',
                'uuid',
                Rule::exists('categorias', 'id')->where(fn ($query) => $query->where('loja_id', $lojaId)),
            ],
            'custo_aquisicao' => ['required', 'numeric', 'min:0'],
            'preco_venda_atual' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(['ativo', 'liquidacao', 'inativo'])],
            'genero' => ['nullable', 'string', 'max:20'],
            'variantes' => ['required', 'array', 'min:1'],
            'variantes.*.tamanho' => ['required', 'string', 'min:1', 'max:50'],
            'variantes.*.quantidade_estoque' => ['required', 'integer', 'min:0'],
            'variantes.*.estoque_minimo_alerta' => ['nullable', 'integer', 'min:0'],
        ];
    }
}