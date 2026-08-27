<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAiService
 *
 * Implementação paralela ao GeminiService para testes comparativos de variância (TCC).
 * Utiliza a API de Chat Completions da OpenAI com Strict Structured Outputs.
 */
class OpenAiService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = (string) config('services.openai.api_key');
        $this->model  = config('services.openai.model', 'meta-llama/llama-3.1-8b-instruct:free');

        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY não configurada no .env');
        }
    }

    public function sugerirCenarios(array $payload): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->retry(2, 500)
            ->post($this->baseUrl, [
                'model' => $this->model,
                'temperature' => 0.0, // Consistência máxima
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $this->systemPrompt()
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)
                    ]
                ],
                // OpenAI Strict Structured Outputs
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'precificacao_cenarios',
                        'strict' => true,
                        'schema' => $this->responseSchema()
                    ]
                ]
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Erro na API da OpenAI: {$response->status()} — {$response->body()}"
            );
        }

        $texto = data_get($response->json(), 'choices.0.message.content');

        if (! $texto) {
            throw new RuntimeException('Resposta da OpenAI veio vazia.');
        }

        $decodificado = json_decode($texto, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Resposta da OpenAI não é um JSON válido.');
        }

        $this->validarCenarios($decodificado);

        return $decodificado;
    }

    private function validarCenarios(array $decodificado): void
    {
        $cenarios = data_get($decodificado, 'cenarios');

        if (! is_array($cenarios) || count($cenarios) !== 3) {
            throw new RuntimeException('OpenAI não retornou exatamente 3 cenários.');
        }

        foreach ($cenarios as $cenario) {
            $margem = data_get($cenario, 'margem_lucro_percentual');
            if (! is_numeric($margem) || $margem < 0.0 || $margem > 0.80) {
                throw new RuntimeException("Margem percentual inválida: {$margem}");
            }
        }
    }

    private function systemPrompt(): string
    {
        // O mesmíssimo prompt do GeminiService para o teste ser justo (A/B testing)
        return <<<PROMPT
Você é um consultor de precificação para lojas de roupas de pequeno e médio porte no Brasil.

Você recebe um payload estruturado em JSON com três camadas de dados:
- camada_1: o preço piso já calculado (cobre todos os custos, sem lucro). Este valor é fixo e não deve ser recalculado por você — use-o apenas como referência mínima absoluta.
- camada_2: dados da loja (posicionamento, regime tributário, canais de venda) e do produto sendo precificado. Sempre presente.
- camada_4: métricas históricas da categoria do produto (giro médio, margem realizada vs. planejada, candidatos à liquidação), além de dois sinais já calculados pelo sistema — razao_margem e razao_giro (ver seção "Como usar razao_margem e razao_giro" abaixo). Pode estar ausente — se estiver ausente no payload, significa que a loja ainda não tem histórico suficiente nessa categoria; não presuma valores nem invente médias de mercado para substituir esse campo.
- Se houver um campo 'aviso' indicando que os dados estão desatualizados, mencione isso brevemente e de forma natural na sua explicação, para que o lojista saiba que as vendas de hoje ainda não impactaram os cálculos de giro e margem.

Sua tarefa: escolher exatamente 3 margens de lucro percentuais, cada uma representando uma estratégia comercial diferente:
1. cenario_liquidacao — margem reduzida, para giro rápido de estoque parado ou baixa saída
2. cenario_ideal — margem equilibrada, alinhada ao posicionamento e à margem_lucro_desejada da loja
3. cenario_alta_demanda — margem elevada, para cenários de sazonalidade, produto popular ou baixa elasticidade de preço percebida

Você NÃO calcula o preço de venda — isso é responsabilidade do sistema, que converte a margem escolhida em preço final depois da sua resposta. Sua saída para cada cenário é a margem_lucro_percentual, como decimal entre 0.0 e 0.80.

Como usar razao_margem e razao_giro para o cenario_liquidacao:
Quando presentes em camada_4, esses dois campos já vêm calculados e normalizados pelo sistema — use-os como referência principal para decidir a margem de liquidação, em vez de tentar interpretar giro_medio_dias sozinho (dias absolutos não são comparáveis entre lojas de portes diferentes).

- razao_margem = margem_realizada_media / margem_planejada_media da categoria.
  < 0.70 → a categoria está rendendo bem abaixo da meta (sinal forte de liquidação agressiva)
  0.70–0.90 → rendendo moderadamente abaixo da meta (liquidação moderada)
  ≥ 0.90 → na meta ou acima (liquidação conservadora, ou considere se este produto precisa mesmo de liquidação)

- razao_giro = giro real da categoria / giro esperado pela própria loja (já normalizado pelo volume de vendas da loja).
  > 1.5 → girando bem mais devagar que o esperado por essa loja especificamente (reforça liquidação agressiva)
  0.8–1.5 → dentro do esperado (sinal neutro)
  < 0.8 → girando mais rápido que o esperado (reforça liquidação conservadora)

razao_margem é o sinal mais direto — priorize-o. razao_giro é um confirmador: quando os dois sinais concordam, sinta-se mais confiante na direção indicada; quando divergem, dê mais peso a razao_margem, mas deixe a divergência refletida na margem escolhida (não ignore o sinal mais fraco) e mencione-a brevemente na explicação. Um ou ambos os campos podem estar ausentes mesmo com camada_4 presente (ex: volume_vendas_esperado zerado para a loja) — trate a ausência de cada campo individualmente, sem impedir o uso do outro.

Regras obrigatórias:
- Se camada_4 estiver ausente (ou se razao_margem e razao_giro estiverem ambos ausentes), não mencione médias de giro ou liquidação como se fossem dados reais — baseie o cenario_liquidacao apenas no posicionamento da loja: popular tende a margens de liquidação mais enxutas, medio intermediárias, premium mais folgadas. Deixe isso explícito na explicação.
- Cada cenário deve incluir uma explicação em português, em linguagem simples, destinada a um lojista sem conhecimento técnico de IA ou estatística — evite jargão.
- A explicação deve citar quais dados do payload embasaram a margem escolhida, apenas quando forem dados reais presentes no payload.
- NÃO mencione valores em reais ou preços específicos na explicação — você não sabe o preço final no momento da sua resposta, apenas o sistema saberá depois de aplicar sua margem. Fale apenas em termos de margem/estratégia (ex: "margem mais enxuta para girar o estoque parado", nunca "preço de R$ 150").
PROMPT;
    }

    private function responseSchema(): array
    {
        // Adaptado levemente para o padrão "Strict" da OpenAI (exige additionalProperties: false)
        return [
            'type' => 'object',
            'properties' => [
                'cenarios' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'enum' => ['cenario_1', 'cenario_2', 'cenario_3']],
                            'tipo' => ['type' => 'string', 'enum' => ['liquidacao', 'ideal', 'alta_demanda']],
                            'margem_lucro_percentual' => ['type' => 'number'],
                            'explicacao' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'tipo', 'margem_lucro_percentual', 'explicacao'],
                        'additionalProperties' => false
                    ]
                ]
            ],
            'required' => ['cenarios'],
            'additionalProperties' => false
        ];
    }
}