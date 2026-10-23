<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sub_categoria_id' => ['required', 'integer', 'exists:sub_categorias,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sub_categoria_id.required' => 'Escolha a sub-categoria.',
            'sub_categoria_id.integer'  => 'Sub-categoria inválida.',
            'sub_categoria_id.exists'   => 'Sub-categoria inválida.',
        ];
    }
}
