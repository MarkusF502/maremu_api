<?php

namespace App\Services;

use RuntimeException;

/**
 * OnboardingIaInterface
 *
 * Contrato comum a qualquer provedor de IA usado para estimar os dados da
 * loja na Tela 2 do onboarding (Gemini, Anthropic, ...). O restante do
 * sistema (OnboardingIaController) depende apenas desta interface — nunca de
 * uma implementação concreta — para que o provedor ativo possa ser trocado
 * via configuração (IA_PROVIDER) sem mudar código de negócio. Mesmo padrão
 * já usado por PrecificacaoIaInterface.
 *
 * A assinatura espelha exatamente o que OnboardingIaService (agora
 * OnboardingIaGeminiService) já fazia antes desta interface existir.
 */
interface OnboardingIaInterface
{
    /**
     * @param  string  $textoDescritivo  Texto livre da Tela 2 (já validado ≥ 50 chars)
     * @param  array{nome_loja: string, regime_tributario: string, canais_marcados: string[]}  $dadosFactuais
     *
     * @return array{
     *   confianca_suficiente: bool,
     *   motivo_baixa_confianca: ?string,
     *   estimativas: ?array<string, array{valor: mixed, explicacao: string, clampado?: bool}>,
     *   canais_sugeridos: string[],
     * }
     *
     * @throws RuntimeException  Erro de API (timeout, 500, JSON malformado) —
     *                           o controller trata isso como trigger de fallback.
     */
    public function estimarDadosLoja(string $textoDescritivo, array $dadosFactuais): array;

    /**
     * Identificador curto do provedor (ex: 'gemini', 'anthropic'), útil para
     * logs/depuração — mesmo papel de PrecificacaoIaInterface::identificador().
     */
    public function identificador(): string;
}
