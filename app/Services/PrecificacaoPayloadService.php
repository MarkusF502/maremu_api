<?php

namespace App\Services;

use App\Models\Produto;
use App\Models\MetricasCategoriaLoja;

/**
 * PrecificacaoPayloadService
 *
 * Responsável exclusivamente por montar o payload estruturado
 * que será enviado à IA (Gemini) no momento do cadastro de produto.
 *
 * Não chama a IA — só lê o banco e organiza os dados.
 * A chamada real à IA fica no PrecificacaoController (Fase 5).
 *
 * Camadas:
 *   Camada 1 → preco_piso (já calculado e salvo no cadastro)
 *   Camada 2 → dados da loja, canais e produto
 *   Camada 4 → métricas agregadas por categoria (se volume mínimo atingido)
 */
class PrecificacaoPayloadService
{
    /**
     * Monta o payload completo para um produto.
     *
     * @param  Produto $produto  Deve ser carregado com: loja.canaisAtivos, categoria
     * @return array{
     *   camada_1: array,
     *   camada_2: array,
     *   camada_4: array|null,
     *   meta: array
     * }
     */
    public function montar(Produto $produto): array
    {
        // Garante que os relacionamentos necessários estão carregados
        $produto->loadMissing(['loja.canaisAtivos', 'categoria']);

        $loja = $produto->loja;

        return [
            'camada_1' => $this->montarCamada1($produto),
            'camada_2' => $this->montarCamada2($produto, $loja),
            'camada_4' => $this->montarCamada4($loja, $produto->categoria_id),
            'meta'     => [
                'produto_id'   => $produto->id,
                'loja_id'      => $loja->id,
                'categoria_id' => $produto->categoria_id,
                'gerado_em'    => now()->toIso8601String(),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // CAMADA 1 — Preço Piso
    // Lê o valor já calculado e gravado pelo PricingEngine no cadastro.
    // O custo_base é incluído para dar contexto à IA (não para recalcular).
    // ─────────────────────────────────────────────────────────────────────

    private function montarCamada1(Produto $produto): array
    {
        return [
            'preco_piso'   => (float) $produto->preco_piso,
            'custo_base'   => (float) ($produto->custo_aquisicao + $produto->frete_entrada_unitario),
            'custo_origem' => [
                'aquisicao' => (float) $produto->custo_aquisicao,
                'frete'     => (float) $produto->frete_entrada_unitario,
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // CAMADA 2 — Dados da Loja + Produto
    // Todos os campos disponíveis, com metadados de origem incluídos.
    // Campos nullable (genero, sku) só entram se preenchidos.
    // ─────────────────────────────────────────────────────────────────────

    private function montarCamada2(Produto $produto, $loja): array
    {
        $dados = [
            'loja' => [
                'posicionamento'           => $loja->posicionamento,
                'regime_tributario'        => $loja->regime_tributario,
                'faturamento_medio_mensal' => (float) $loja->faturamento_medio_mensal,
                'custo_fixo_mensal'        => (float) $loja->custo_fixo_mensal,
                'custo_fixo_origem'        => $loja->custo_fixo_origem,
                'margem_lucro_desejada'    => (float) $loja->margem_lucro_desejada,
                'aliquota_efetiva'         => (float) $loja->aliquota_efetiva,
                'aliquota_origem'          => $loja->aliquota_origem,
                'volume_vendas_esperado'   => (int) $loja->volume_vendas_esperado,
            ],
            'canais' => $loja->canaisAtivos->map(fn($c) => [
                'canal'           => $c->canal,
                'taxa_percentual' => (float) $c->taxa_percentual,
                'taxa_origem'     => $c->taxa_origem,
            ])->values()->toArray(),
            'produto' => [
                'nome'       => $produto->nome,
                'categoria'  => $produto->categoria?->nome,
                'status'     => $produto->status,
            ],
        ];

        // Campos opcionais — só incluídos se não forem nulos
        if (!is_null($produto->genero)) {
            $dados['produto']['genero'] = $produto->genero;
        }
        if (!is_null($produto->sku)) {
            $dados['produto']['sku'] = $produto->sku;
        }

        return $dados;
    }

    // ─────────────────────────────────────────────────────────────────────
    // CAMADA 4 — Métricas Agregadas da Categoria
    //
    // Só incluída quando volume_minimo_atingido = true.
    // Busca o registro mais recente de metricas_categoria_loja
    // para aquela combinação loja + categoria.
    // ─────────────────────────────────────────────────────────────────────

    private function montarCamada4($loja, string $categoriaId): ?array
    {
        $metrica = MetricasCategoriaLoja::where('loja_id', $loja->id)
            ->where('categoria_id', $categoriaId)
            ->where('volume_minimo_atingido', true)
            ->orderByDesc('periodo_referencia')  // mais recente primeiro
            ->first();

        if (!$metrica) {
            return null; // omitido do payload — a IA não recebe esse campo
        }

        $dados = [
            'periodo_referencia'     => $metrica->periodo_referencia,
            'giro_medio_dias'        => $metrica->giro_medio_dias !== null
                                            ? (float) $metrica->giro_medio_dias
                                            : null,
            'margem_realizada_media' => $metrica->margem_realizada_media !== null
                                            ? (float) $metrica->margem_realizada_media
                                            : null,
            'margem_planejada_media' => $metrica->margem_planejada_media !== null
                                            ? (float) $metrica->margem_planejada_media
                                            : null,
            'qtd_vendas_periodo'     => (int) $metrica->qtd_vendas_periodo,
            'data_calculo'           => $metrica->data_calculo,
        ];

        // Sinais derivados para orientar a margem do cenario_liquidacao (ver
        // Especificação Técnica — normalização de giro por loja). São
        // calculados aqui, não no PricingEngine, porque dependem só de dados
        // já presentes nesta camada + volume_vendas_esperado da loja — não
        // envolvem custo, preço ou nenhuma regra de negócio de precificação.
        $dados['razao_margem'] = $this->calcularRazaoMargem(
            $metrica->margem_realizada_media,
            $metrica->margem_planejada_media
        );

        $dados['razao_giro'] = $this->calcularRazaoGiro(
            $metrica->giro_medio_dias,
            (int) $loja->volume_vendas_esperado
        );

        // candidatos_liquidacao é JSON nullable — só inclui se houver dados
        if (!empty($metrica->candidatos_liquidacao)) {
            $dados['candidatos_liquidacao'] = $metrica->candidatos_liquidacao;
        }

        if ($metrica->desatualizada) {
            $dados['aviso'] = 'Métricas da categoria com vendas recentes ainda não consolidadas. Atualização programada para a próxima madrugada.';
        }

        // Remove campos nulos do array antes de entregar
        return array_filter($dados, fn($v) => !is_null($v));
    }

    /**
     * Razão de giro: giro real da categoria vs. giro esperado pela própria
     * loja, derivado de volume_vendas_esperado. Normaliza dias absolutos
     * pelo ritmo que cada loja projetou para si mesma — uma loja pequena e
     * uma grande não são comparáveis em dias fixos (ver nota metodológica
     * na Especificação Técnica).
     *
     * > 1.5  → categoria girando bem mais devagar que o esperado
     * 0.8–1.5 → dentro do esperado
     * < 0.8  → girando mais rápido que o esperado
     *
     * Retorna null quando não é possível calcular (dado ausente ou
     * volume_vendas_esperado = 0 — loja recém-cadastrada sem essa
     * informação ainda preenchida). null aqui é tratado como fallback,
     * nunca como zero.
     */
    private function calcularRazaoGiro(?float $giroMedioDias, int $volumeVendasEsperado): ?float
    {
        if ($giroMedioDias === null || $volumeVendasEsperado <= 0) {
            return null;
        }

        $giroEsperadoDias = 30 / ($volumeVendasEsperado / 30);

        return round($giroMedioDias / $giroEsperadoDias, 4);
    }

    /**
     * Razão de margem: margem realizada vs. planejada da categoria.
     * Já é auto-normalizada (razão de percentuais), não depende do porte
     * da loja.
     *
     * < 0.7    → performando bem abaixo da meta
     * 0.7–0.9  → levemente abaixo
     * > 0.9    → perto ou acima da meta
     *
     * Retorna null quando não é possível calcular (dado ausente ou
     * margem_planejada_media = 0 — situação anômala de cadastro que não
     * deveria ocorrer, mas não se resolve inventando um divisor).
     */
    private function calcularRazaoMargem(?float $margemRealizada, ?float $margemPlanejada): ?float
    {
        if ($margemRealizada === null || $margemPlanejada === null || $margemPlanejada <= 0) {
            return null;
        }

        return round($margemRealizada / $margemPlanejada, 4);
    }
}