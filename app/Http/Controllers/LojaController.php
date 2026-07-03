<?php

namespace App\Http\Controllers;

use App\Http\Requests\OnboardingInferirRequest;
use App\Http\Requests\OnboardingSalvarRequest;
use App\Models\CanalVendaLoja;
use App\Models\Loja;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class LojaController extends Controller
{
    public function __construct(private readonly OnboardingService $onboardingService) {}

    // ─────────────────────────────────────────────────────────────────────
    // ETAPA 1 — Inferir dados a partir das 4 perguntas
    // POST /api/loja/onboarding/inferir
    //
    // Não salva nada. Devolve os campos pré-preenchidos para o frontend
    // montar a tela de revisão com os valores já preenchidos.
    // ─────────────────────────────────────────────────────────────────────

    public function inferir(OnboardingInferirRequest $request): JsonResponse
    {
        $inferidos = $this->onboardingService->inferirDadosDaLoja(
            faixaFaturamento: $request->faixa_faturamento,
            posicionamento:   $request->posicionamento,
            regime:           $request->regime,
            canais:           $request->canais,
        );

        return response()->json([
            // Devolve o nome para o frontend pré-preencher o campo na revisão
            'nome'    => $request->nome,
            'loja'    => $inferidos['loja'],
            'canais'  => $inferidos['canais'],
            'resumo'  => $inferidos['resumo'],
            'tooltips' => $this->onboardingService->descricoesDosTooltips(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ETAPA 2 — Salvar os dados revisados/confirmados pelo lojista
    // POST /api/loja/onboarding/salvar
    //
    // Recebe os dados já revisados (que podem ter sido editados na tela
    // de configurações) e salva no banco.
    // Impede que o mesmo usuário crie duas lojas.
    // ─────────────────────────────────────────────────────────────────────

    public function salvar(OnboardingSalvarRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->loja()->exists()) {
            return response()->json([
                'message' => 'Você já possui uma loja cadastrada.',
                'code'    => 'loja_ja_existe',
            ], 422);
        }

        $loja = DB::transaction(function () use ($request, $user) {
            $loja = Loja::create([
                'user_id'                  => $user->id,
                'nome'                     => $request->nome,
                'posicionamento'           => $request->posicionamento,
                'regime_tributario'        => $request->regime_tributario,
                'faturamento_medio_mensal' => $request->faturamento_medio_mensal,
                'custo_fixo_mensal'        => $request->custo_fixo_mensal,
                'custo_fixo_origem'        => $request->custo_fixo_origem,
                'margem_lucro_desejada'    => $request->margem_lucro_desejada,
                'aliquota_efetiva'         => $request->aliquota_efetiva,
                'aliquota_origem'          => $request->aliquota_origem,
                'volume_vendas_esperado'   => $request->volume_vendas_esperado,
            ]);

            foreach ($request->canais as $canal) {
                CanalVendaLoja::create([
                    'loja_id'         => $loja->id,
                    'canal'           => $canal['canal'],
                    'taxa_percentual' => $canal['taxa_percentual'],
                    'taxa_origem'     => $canal['taxa_origem'],
                    'ativo'           => true,
                ]);
            }

            return $loja;
        });

        return response()->json([
            'message' => 'Loja cadastrada com sucesso.',
            'loja'    => $loja->load('canais'),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────
    // CONFIGURAÇÕES — Ler dados da loja do usuário autenticado
    // GET /api/loja/configuracoes
    // ─────────────────────────────────────────────────────────────────────

    public function configuracoes(Request $request): JsonResponse
    {
        $loja = $request->user()->loja()->with('canaisAtivos')->firstOrFail();

        return response()->json([
            'loja'    => $loja,
            'canais'  => $loja->canaisAtivos,
            'tooltips' => $this->onboardingService->descricoesDosTooltips(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // CONFIGURAÇÕES — Atualizar dados da loja
    // PUT /api/loja/configuracoes
    //
    // Reutiliza o mesmo FormRequest do salvar — mesmas regras de validação.
    // ─────────────────────────────────────────────────────────────────────

    public function atualizarConfiguracoes(OnboardingSalvarRequest $request): JsonResponse
    {
        $user = $request->user();
        $loja = $user->loja()->firstOrFail();

        DB::transaction(function () use ($request, $loja) {
            $loja->update([
                'nome'                     => $request->nome,
                'posicionamento'           => $request->posicionamento,
                'regime_tributario'        => $request->regime_tributario,
                'faturamento_medio_mensal' => $request->faturamento_medio_mensal,
                'custo_fixo_mensal'        => $request->custo_fixo_mensal,
                'custo_fixo_origem'        => $request->custo_fixo_origem,
                'margem_lucro_desejada'    => $request->margem_lucro_desejada,
                'aliquota_efetiva'         => $request->aliquota_efetiva,
                'aliquota_origem'          => $request->aliquota_origem,
                'volume_vendas_esperado'   => $request->volume_vendas_esperado,
            ]);

            // Recria os canais: remove os existentes e insere os novos
            // Simples e seguro para o volume de canais que uma loja terá (máx. 3)
            $loja->canais()->delete();

            foreach ($request->canais as $canal) {
                CanalVendaLoja::create([
                    'loja_id'         => $loja->id,
                    'canal'           => $canal['canal'],
                    'taxa_percentual' => $canal['taxa_percentual'],
                    'taxa_origem'     => $canal['taxa_origem'],
                    'ativo'           => true,
                ]);
            }
        });

        return response()->json([
            'message' => 'Configurações atualizadas com sucesso.',
            'loja'    => $loja->fresh()->load('canaisAtivos'),
        ]);
    }
}