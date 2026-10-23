<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoriaRequest;
use App\Http\Requests\UpdateCategoriaRequest;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;

class CategoriaController extends Controller
{
    /**
     * Relação carregada nas respostas, para o frontend receber a classificação
     * no mesmo formato da listagem.
     */
    private const COM_CLASSIFICACAO = [
        'subCategoria:id,categoria_pai_id,nome,complementar',
        'subCategoria.categoriaPai:id,nome',
    ];

    public function index(): JsonResponse
    {
        $loja = request()->user()->loja()->firstOrFail();

        $categorias = $loja->categorias()
            ->with(self::COM_CLASSIFICACAO)
            ->orderBy('nome')
            ->get();

        return response()->json([
            'categorias' => $categorias,
            // Categorias anteriores à taxonomia: ficam fora da comparação
            // entre lojas até o lojista classificá-las.
            'pendentes_classificacao' => $categorias->whereNull('sub_categoria_id')->count(),
        ]);
    }

    public function store(StoreCategoriaRequest $request): JsonResponse
    {
        $loja = $request->user()->loja()->firstOrFail();

        $categoria = Categoria::create([
            'loja_id' => $loja->id,
            'nome' => $request->nome,
            'sub_categoria_id' => $request->input('sub_categoria_id'),
        ]);

        return response()->json([
            'message' => 'Categoria criada com sucesso.',
            'categoria' => $categoria->load(self::COM_CLASSIFICACAO),
        ], 201);
    }

    /**
     * Classifica uma categoria da loja na taxonomia fixa.
     */
    public function update(UpdateCategoriaRequest $request, Categoria $categoria): JsonResponse
    {
        $loja = $request->user()->loja()->firstOrFail();

        abort_unless($categoria->loja_id === $loja->id, 404);

        $categoria->update(['sub_categoria_id' => $request->integer('sub_categoria_id')]);

        return response()->json([
            'message' => 'Categoria classificada com sucesso.',
            'categoria' => $categoria->load(self::COM_CLASSIFICACAO),
        ]);
    }
}
