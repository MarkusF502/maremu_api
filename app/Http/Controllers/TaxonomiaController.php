<?php

namespace App\Http\Controllers;

use App\Models\CategoriaPai;
use Illuminate\Http\JsonResponse;

class TaxonomiaController extends Controller
{
    /**
     * Árvore fixa de categorias-pai e sub-categorias, para o dropdown encadeado.
     */
    public function index(): JsonResponse
    {
        $pais = CategoriaPai::with('subCategorias:id,categoria_pai_id,nome')
            ->orderBy('ordem')->orderBy('nome')
            ->get(['id', 'nome']);

        return response()->json([
            'categorias_pai' => $pais->map(fn (CategoriaPai $pai) => [
                'id' => $pai->id,
                'nome' => $pai->nome,
                'sub_categorias' => $pai->subCategorias->map(fn ($sub) => [
                    'id' => $sub->id,
                    'nome' => $sub->nome,
                ])->values(),
            ])->values(),
        ]);
    }
}
