<?php

namespace App\Services\Concerns;

use RuntimeException;

/**
 * PrecificacaoIaPrompt
 *
 * Prompt de sistema, schema de resposta e validação de saída compartilhados
 * por todos os provedores de IA de precificação (GeminiService, AnthropicService,
 * ...). Extraído para evitar que cada provedor mantenha sua própria cópia do
 * texto — a Anthropic separa `system` de `messages` de forma mais rígida que o
 * Gemini, mas o CONTEÚDO das regras precisa ser idêntico, senão a comparação
 * de variância entre provedores fica contaminada por prompts divergentes.
 */
trait PrecificacaoIaPrompt
{
    protected function systemPrompt(): string
    {
        return <<<PROMPT
Você é um consultor de precificação para lojas de roupas de pequeno e médio porte no Brasil.

Você recebe um payload estruturado em JSON com três camadas de dados:

camada_1: o preço piso já calculado (cobre todos os custos, sem lucro). Este valor é fixo e não deve ser recalculado por você — use-o apenas como referência mínima absoluta.
camada_2: dados da loja (posicionamento, regime tributário, canais de venda) e do produto sendo precificado. Sempre presente.
camada_4: métricas históricas da categoria do produto (giro médio, margem realizada vs. planejada, candidatos à liquidação). Pode estar ausente — se estiver ausente no payload, significa que a loja ainda não tem histórico suficiente nessa categoria; não presuma valores nem invente médias de mercado para substituir esse campo.
Se houver um campo 'aviso' indicando que os dados estão desatualizados, mencione isso brevemente e de forma natural na sua explicação, para que o lojista saiba que as vendas de hoje ainda não impactaram os cálculos de giro e margem.

Sua tarefa: escolher exatamente 3 margens de lucro percentuais, cada uma representando uma estratégia comercial diferente:

cenario_liquidacao — margem reduzida, para giro rápido de estoque parado ou baixa saída. Use o posicionamento da loja (camada_2) como âncora principal para calibrar o quão agressiva essa redução deve ser: quanto mais popular o posicionamento, mais agressiva (mais baixa) a margem de liquidação; quanto mais premium, mais conservadora (menos reduzida) a margem, já que descontos muito agressivos podem contradizer o posicionamento da marca aos olhos do cliente.
cenario_ideal — margem equilibrada, alinhada ao posicionamento e à margem_lucro_desejada da loja
cenario_alta_demanda — margem elevada, para cenários de sazonalidade, produto popular ou baixa elasticidade de preço percebida

Você NÃO calcula o preço de venda — isso é responsabilidade do sistema, que converte a margem escolhida em preço final depois da sua resposta. Sua saída para cada cenário é a margem_lucro_percentual, como decimal entre 0.0 e 0.80.

Regras obrigatórias:

Se camada_4 estiver ausente, não mencione médias de giro ou liquidação como se fossem dados reais — baseie o cenario_liquidacao apenas no posicionamento e na margem desejada da loja, e deixe isso explícito na explicação.
Cada cenário deve incluir uma explicação em português, em linguagem simples, destinada a um lojista sem conhecimento técnico de IA ou estatística — evite jargão.
A explicação deve citar quais dados do payload embasaram a margem escolhida, apenas quando forem dados reais presentes no payload.
NÃO mencione valores em reais ou preços específicos na explicação — você não sabe o preço final no momento da sua resposta, apenas o sistema saberá depois de aplicar sua margem. Fale apenas em termos de margem/estratégia (ex: "margem mais enxuta para girar o estoque parado", nunca "preço de R$ 150").
PROMPT;
    }

    protected function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cenarios' => [
                    'type' => 'array',
                    'minItems' => 3,
                    'maxItems' => 3,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id'                       => ['type' => 'string', 'enum' => ['cenario_1', 'cenario_2', 'cenario_3']],
                            'tipo'                     => ['type' => 'string', 'enum' => ['liquidacao', 'ideal', 'alta_demanda']],
                            'margem_lucro_percentual'  => [
                                'type'    => 'number',
                                'minimum' => 0.0,
                                'maximum' => 0.80, // teto de segurança — margem acima de 80% é improvável em varejo
                            ],
                            'explicacao'               => ['type' => 'string'],
                        ],
                        'required' => ['id', 'tipo', 'margem_lucro_percentual', 'explicacao'],
                    ],
                ],
            ],
            'required' => ['cenarios'],
        ];
    }

    /**
     * Validação de segurança: garante que o modelo respeitou as regras
     * mínimas antes de deixar o dado seguir para o banco/frontend.
     * Nunca confie cegamente em saída de LLM para dados financeiros.
     */
    protected function validarCenarios(array $decodificado): void
    {
        $cenarios = data_get($decodificado, 'cenarios');

        if (! is_array($cenarios) || count($cenarios) !== 3) {
            throw new RuntimeException('IA não retornou exatamente 3 cenários.');
        }

        foreach ($cenarios as $cenario) {
            $margem = data_get($cenario, 'margem_lucro_percentual');

            // Defesa em profundidade: o schema já restringe [0.0, 0.80], mas
            // nunca confiamos cegamente em saída de LLM para dado financeiro —
            // o schema é uma instrução ao modelo, não uma garantia estrutural.
            if (! is_numeric($margem) || $margem < 0.0 || $margem > 0.80) {
                throw new RuntimeException(
                    "Cenário '{$cenario['id']}' com margem_lucro_percentual inválida ({$margem}). Esperado entre 0.0 e 0.80."
                );
            }
        }
    }
}
