<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RegrasDeCategoria;
use Illuminate\Foundation\Http\FormRequest;

class StoreCategoriaRequest extends FormRequest
{
    use RegrasDeCategoria;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', ...$this->regrasDoNome()],
            'sub_categoria_id' => ['required', ...$this->regrasDaSubCategoria()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required'             => 'Informe o nome da categoria.',
            'sub_categoria_id.required' => 'Escolha a sub-categoria.',
            ...$this->mensagensDaCategoria(),
        ];
    }
}
