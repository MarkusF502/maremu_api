<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoriaRequest;
use App\Models\Categoria;
use Illuminate\Http\JsonResponse;

class CategoriaController extends Controller
{
    public function index(): JsonResponse
    {
        $loja = request()->user()->loja()->firstOrFail();

        $categorias = $loja->categorias()->orderBy('nome')->get();

        return response()->json([
            'categorias' => $categorias,
        ]);
    }

    public function store(StoreCategoriaRequest $request): JsonResponse
    {
        $loja = $request->user()->loja()->firstOrFail();

        $categoria = Categoria::create([
            'loja_id' => $loja->id,
            'nome' => $request->nome,
        ]);

        return response()->json([
            'message' => 'Categoria criada com sucesso.',
            'categoria' => $categoria,
        ], 201);
    }
}