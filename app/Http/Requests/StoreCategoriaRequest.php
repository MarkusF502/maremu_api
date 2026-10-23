<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $lojaId = $this->user()?->loja?->id;

        return [
            'nome' => [
                'required',
                'string',
                'min:2',
                'max:100',
                Rule::unique('categorias', 'nome')->where(fn ($query) => $query->where('loja_id', $lojaId)),
            ],
            'sub_categoria_id' => ['required', 'integer', 'exists:sub_categorias,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required'             => 'Informe o nome da categoria.',
            'nome.string'               => 'Informe o nome da categoria.',
            'nome.min'                  => 'O nome deve ter entre 2 e 100 caracteres.',
            'nome.max'                  => 'O nome deve ter entre 2 e 100 caracteres.',
            'nome.unique'               => 'Você já tem uma categoria com esse nome.',
            'sub_categoria_id.required' => 'Escolha a sub-categoria.',
            'sub_categoria_id.integer'  => 'Sub-categoria inválida.',
            'sub_categoria_id.exists'   => 'Sub-categoria inválida.',
        ];
    }
}