<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\RegrasDeCategoria;
use App\Models\Categoria;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoriaRequest extends FormRequest
{
    use RegrasDeCategoria;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Os dois campos são opcionais, mas um PATCH sem nenhum deles não faz nada:
     * required_without exige ao menos um e deixa o outro de fora quando não vem.
     */
    public function rules(): array
    {
        $categoria = $this->route('categoria');

        return [
            'nome' => [
                'required_without:sub_categoria_id',
                ...$this->regrasDoNome($categoria instanceof Categoria ? $categoria : null),
            ],
            'sub_categoria_id' => [
                'required_without:nome',
                ...$this->regrasDaSubCategoria(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required_without'             => 'Informe o nome ou a sub-categoria.',
            'sub_categoria_id.required_without' => 'Informe o nome ou a sub-categoria.',
            ...$this->mensagensDaCategoria(),
        ];
    }
}
