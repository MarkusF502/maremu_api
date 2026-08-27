<?php

namespace App\Services;

use RuntimeException;

/**
 * PrecificacaoIaInterface
 *
 * Contrato comum a qualquer provedor de IA usado para sugerir margens de
 * precificação (Gemini, Anthropic, ...). O restante do sistema
 * (PrecificacaoController, TestarVarianciaPrecificacao) depende apenas
 * desta interface — nunca de uma implementação concreta — para que o
 * provedor ativo possa ser trocado via configuração (IA_PROVIDER) sem
 * mudar código de negócio.
 *
 * A assinatura espelha exatamente o que GeminiService já fazia antes desta
 * interface existir: nenhuma implementação calcula preço, todas devolvem
 * apenas os 3 cenários com margem_lucro_percentual.
 */
interface PrecificacaoIaInterface
{
    /**
     * Envia o payload de precificação e retorna os 3 cenários já decodificados.
     *
     * @param  array  $payload  Saída de PrecificacaoPayloadService::montar()
     * @return array{cenarios: array<array{id: string, tipo: string, margem_lucro_percentual: float, explicacao: string}>}
     *
     * @throws RuntimeException em caso de erro de API ou resposta fora do schema
     */
    public function sugerirCenarios(array $payload): array;

    /**
     * Identificador curto do provedor (ex: 'gemini', 'anthropic'), gravado
     * em logs_sugestao_ia.provedor_ia para permitir separar resultados por
     * provedor sem precisar cruzar com logs de aplicação.
     */
    public function identificador(): string;
}
