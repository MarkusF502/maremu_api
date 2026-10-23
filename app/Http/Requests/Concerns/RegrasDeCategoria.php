<?php

namespace App\Http\Requests\Concerns;

use App\Models\Categoria;
use Illuminate\Validation\Rule;

/**
 * Regras e mensagens do nome e da classificação da categoria, compartilhadas
 * entre o cadastro e a edição para as duas rotas responderem igual.
 */
trait RegrasDeCategoria
{
    /**
     * @return array<int, mixed>
     */
    protected function regrasDoNome(?Categoria $ignorar = null): array
    {
        $lojaId = $this->user()?->loja?->id;

        $unica = Rule::unique('categorias', 'nome')
            ->where(fn ($query) => $query->where('loja_id', $lojaId));

        return ['string', 'min:2', 'max:100', $ignorar ? $unica->ignore($ignorar) : $unica];
    }

    /**
     * @return array<int, string>
     */
    protected function regrasDaSubCategoria(): array
    {
        return ['integer', 'exists:sub_categorias,id'];
    }

    /**
     * @return array<string, string>
     */
    protected function mensagensDaCategoria(): array
    {
        return [
            'nome.string'              => 'Informe o nome da categoria.',
            'nome.min'                 => 'O nome deve ter entre 2 e 100 caracteres.',
            'nome.max'                 => 'O nome deve ter entre 2 e 100 caracteres.',
            'nome.unique'              => 'Você já tem uma categoria com esse nome.',
            'sub_categoria_id.integer' => 'Sub-categoria inválida.',
            'sub_categoria_id.exists'  => 'Sub-categoria inválida.',
        ];
    }
}
