<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureLojaExists
 *
 * Verifica se o usuário autenticado já possui uma loja cadastrada.
 * Se não tiver, retorna 403 com um código específico para o frontend
 * redirecionar para o fluxo de onboarding.
 *
 * O frontend (Next.js) trata o código 'loja_nao_cadastrada' e redireciona
 * para /onboarding — o middleware não faz redirect direto porque a API
 * é desacoplada do frontend.
 */
class EnsureLojaExists
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->loja()->exists()) {
            return response()->json([
                'message' => 'Loja não cadastrada.',
                'code'    => 'loja_nao_cadastrada',
            ], 403);
        }

        return $next($request);
    }
}