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
     * @param  array  $payload  Saída de PrecificacaoPayloadService::montar()
     * @return array{cenarios: array}
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

        $this->validarCenarios($decodificado, (float) data_get($payload, 'camada_1.preco_piso'));

        return $decodificado;
    }

    /**
     * Validação de segurança: garante que o modelo respeitou as regras
     * mínimas antes de deixar o dado seguir para o banco/frontend.
     * Nunca confie cegamente em saída de LLM para dados financeiros.
     */
    private function validarCenarios(array $decodificado, float $precoPiso): void
    {
        $cenarios = data_get($decodificado, 'cenarios');

        if (! is_array($cenarios) || count($cenarios) !== 3) {
            throw new RuntimeException('Gemini não retornou exatamente 3 cenários.');
        }

        foreach ($cenarios as $cenario) {
            $preco = data_get($cenario, 'preco_sugerido');

            if (! is_numeric($preco) || $preco < $precoPiso) {
                throw new RuntimeException(
                    "Cenário '{$cenario['id']}' com preço inválido (R$ {$preco}) abaixo do preço piso (R\$ {$precoPiso})."
                );
            }
        }
    }

    private function systemPrompt(): string
    {
        return <<<PROMPT
Você é um consultor de precificação para lojas de roupas de pequeno e médio porte no Brasil.

Você recebe um payload estruturado em JSON com três camadas de dados:
- camada_1: o preço piso já calculado (cobre todos os custos, sem lucro). Este valor é fixo e não deve ser recalculado por você — use-o apenas como referência mínima absoluta.
- camada_2: dados da loja (posicionamento, regime tributário, canais de venda) e do produto sendo precificado. Sempre presente.
- camada_4: métricas históricas da categoria do produto (giro médio, margem realizada vs. planejada, candidatos à liquidação). Pode estar ausente — se estiver ausente no payload, significa que a loja ainda não tem histórico suficiente nessa categoria; não presuma valores nem invente médias de mercado para substituir esse campo.
- Se houver um campo 'aviso' indicando que os dados estão desatualizados, mencione isso brevemente e de forma natural na sua explicação, para que o lojista saiba que as vendas de hoje ainda não impactaram os cálculos de giro e margem.

Sua tarefa: gerar exatamente 3 cenários de preço de venda, cada um acima do preco_piso, representando diferentes estratégias comerciais:
1. cenario_liquidacao — margem reduzida, para giro rápido de estoque parado ou baixa saída
2. cenario_ideal — margem equilibrada, alinhada ao posicionamento e à margem_lucro_desejada da loja
3. cenario_alta_demanda — margem elevada, para cenários de sazonalidade, produto popular ou baixa elasticidade de preço percebida

Regras obrigatórias:
- Nenhum cenário pode ter preço abaixo de camada_1.preco_piso.
- Se camada_4 estiver ausente, não mencione médias de giro ou liquidação como se fossem dados reais — baseie o cenario_liquidacao apenas no posicionamento e na margem desejada da loja, e deixe isso explícito na explicação.
- Cada cenário deve incluir uma explicação em português, em linguagem simples, destinada a um lojista sem conhecimento técnico de IA ou estatística — evite jargão.
- A explicação deve citar quais dados do payload embasaram aquele preço, apenas quando forem dados reais presentes no payload.
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
                            'id'             => ['type' => 'string', 'enum' => ['cenario_1', 'cenario_2', 'cenario_3']],
                            'tipo'           => ['type' => 'string', 'enum' => ['liquidacao', 'ideal', 'alta_demanda']],
                            'preco_sugerido' => ['type' => 'number'],
                            'explicacao'     => ['type' => 'string'],
                        ],
                        'required' => ['id', 'tipo', 'preco_sugerido', 'explicacao'],
                    ],
                ],
            ],
            'required' => ['cenarios'],
        ];
    }
}