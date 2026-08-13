<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * GeminiService
 *
 * Responsável exclusivamente por chamar a API do Gemini e devolver
 * os cenários já validados como array. Não conhece o payload de negócio
 * (isso é papel do PrecificacaoPayloadService) nem grava nada no banco
 * (isso é papel do Controller).
 */
class GeminiService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model  = config('services.gemini.model', 'gemini-1.5-flash-latest');

        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY não configurada no .env');
        }
    }

    /**
     * Envia o payload de precificação e retorna os 3 cenários já decodificados.
     *
     * Cada cenário vem apenas com a margem_lucro_percentual escolhida pela IA —
     * o preco_sugerido NÃO é calculado aqui. É responsabilidade de quem chama
     * este método (PrecificacaoController) converter a margem em preço via
     * PricingEngine::calcularPreco(), usando os dados reais do produto/loja.
     *
     * @param  array  $payload  Saída de PrecificacaoPayloadService::montar()
     * @return array{cenarios: array<array{id: string, tipo: string, margem_lucro_percentual: float, explicacao: string}>}
     *
     * @throws RuntimeException em caso de erro de API ou resposta fora do schema
     */
    public function sugerirCenarios(array $payload): array
    {
        $response = Http::timeout(30)
            ->retry(2, 500) // 2 tentativas extras, 500ms entre elas — chamadas de API externa falham eventualmente
            ->post("{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}", [
                'systemInstruction' => [
                    'parts' => [['text' => $this->systemPrompt()]],
                ],
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
                    ],
                ],
                'generationConfig' => [
                    'temperature'      => 0.0, // baixa: queremos consistência, não criatividade
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => $this->responseSchema(),
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Erro na API do Gemini: {$response->status()} — {$response->body()}"
            );
        }

        $texto = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (! $texto) {
            throw new RuntimeException('Resposta do Gemini veio vazia ou em formato inesperado.');
        }

        $decodificado = json_decode($texto, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Resposta do Gemini não é um JSON válido: ' . json_last_error_msg());
        }

        $this->validarCenarios($decodificado);

        return $decodificado;
    }

    /**
     * Validação de segurança: garante que o modelo respeitou as regras
     * mínimas antes de deixar o dado seguir para o banco/frontend.
     * Nunca confie cegamente em saída de LLM para dados financeiros.
     */
    private function validarCenarios(array $decodificado): void
    {
        $cenarios = data_get($decodificado, 'cenarios');

        if (! is_array($cenarios) || count($cenarios) !== 3) {
            throw new RuntimeException('Gemini não retornou exatamente 3 cenários.');
        }

        foreach ($cenarios as $cenario) {
            $margem = data_get($cenario, 'margem_lucro_percentual');

            // Defesa em profundidade: o responseSchema já restringe [0.0, 0.80],
            // mas nunca confiamos cegamente em saída de LLM para dado financeiro —
            // o schema é uma instrução ao modelo, não uma garantia estrutural.
            if (! is_numeric($margem) || $margem < 0.0 || $margem > 0.80) {
                throw new RuntimeException(
                    "Cenário '{$cenario['id']}' com margem_lucro_percentual inválida ({$margem}). Esperado entre 0.0 e 0.80."
                );
            }
        }
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
Você é um consultor de precificação para lojas de roupas de pequeno e médio porte no Brasil.

Você recebe um payload estruturado em JSON com três camadas de dados, mais um campo de instrução:
- camada_1: o preço piso já calculado (cobre todos os custos, sem lucro). Este valor é fixo e não deve ser recalculado por você — use-o apenas como referência mínima absoluta.
- camada_2: dados da loja (posicionamento, regime tributário, canais de venda) e do produto sendo precificado. Sempre presente.
- camada_4: métricas históricas da categoria do produto (giro médio, margem realizada vs. planejada, candidatos à liquidação), além de dois sinais já calculados pelo sistema — razao_margem e razao_giro. Pode estar ausente — se estiver ausente no payload, significa que a loja ainda não tem histórico suficiente nessa categoria; não presuma valores nem invente médias de mercado para substituir esse campo.
- instrucao_liquidacao: um campo fora da numeração de camadas, contendo a margem de liquidação que o sistema já calculou de forma determinística (campo margem_definida) — ver seção "Cenário de liquidação" abaixo. Sempre presente.
- Se houver um campo 'aviso' indicando que os dados estão desatualizados, mencione isso brevemente e de forma natural na sua explicação, para que o lojista saiba que as vendas de hoje ainda não impactaram os cálculos de giro e margem.

Sua tarefa: retornar exatamente 3 cenários, cada um com uma margem de lucro percentual e uma explicação, representando estratégias comerciais diferentes:
1. cenario_liquidacao — margem reduzida, para giro rápido de estoque parado ou baixa saída
2. cenario_ideal — margem equilibrada, alinhada ao posicionamento e à margem_lucro_desejada da loja
3. cenario_alta_demanda — margem elevada, para cenários de sazonalidade, produto popular ou baixa elasticidade de preço percebida

Você NÃO calcula o preço de venda — isso é responsabilidade do sistema, que converte a margem de cada cenário em preço final depois da sua resposta.

Cenário de liquidação — a margem já está definida, você só explica:
Diferente dos outros dois cenários, você NÃO escolhe a margem de cenario_liquidacao. Ela já foi calculada pelo sistema, de forma determinística, e está em instrucao_liquidacao.margem_definida. Sua única tarefa para esse cenário é:
1. Copiar exatamente esse valor no campo margem_lucro_percentual da sua resposta (não arredonde, não ajuste, não reinterprete).
2. Escrever uma explicação em linguagem simples de por que essa margem faz sentido, usando razao_margem e razao_giro (de camada_4, quando presentes) como base:
   - razao_margem = margem_realizada_media / margem_planejada_media. Quanto mais abaixo de 1, mais a categoria está rendendo abaixo da meta.
   - razao_giro = giro real da categoria / giro esperado pela própria loja. Quanto mais acima de 1, mais devagar a categoria está girando em relação ao esperado por essa loja.
   Cite esses sinais na explicação apenas quando estiverem presentes no payload. Se camada_4 estiver ausente (ou se razao_margem e razao_giro estiverem ambos ausentes), não mencione médias de giro ou liquidação como se fossem dados reais — explique a margem com base no posicionamento da loja (instrucao_liquidacao.origem informará se a margem veio de 'score' ou de 'fallback_posicionamento', mas essa distinção é só para o sistema; não precisa mencioná-la na explicação).

Para cenario_ideal e cenario_alta_demanda, a margem_lucro_percentual continua sendo sua decisão livre, como decimal entre 0.0 e 0.80.

Regras obrigatórias:
- Cada cenário deve incluir uma explicação em português, em linguagem simples, destinada a um lojista sem conhecimento técnico de IA ou estatística — evite jargão.
- A explicação deve citar quais dados do payload embasaram a margem, apenas quando forem dados reais presentes no payload.
- NÃO mencione valores em reais ou preços específicos na explicação — você não sabe o preço final no momento da sua resposta, apenas o sistema saberá depois de aplicar a margem. Fale apenas em termos de margem/estratégia (ex: "margem mais enxuta para girar o estoque parado", nunca "preço de R$ 150").
PROMPT;
    }

    private function responseSchema(): array
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
}