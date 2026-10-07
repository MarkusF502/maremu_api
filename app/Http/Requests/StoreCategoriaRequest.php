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
            // Opcional enquanto o frontend não envia o campo; passa a ser
            // obrigatório junto com o dropdown de taxonomia no cadastro.
            'sub_categoria_id' => ['nullable', 'integer', 'exists:sub_categorias,id'],
        ];
    }
}