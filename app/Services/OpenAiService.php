<?php

namespace App\Services;

use App\Services\Concerns\PrecificacaoIaPrompt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenAiService
 *
 * Implementação paralela ao GeminiService/AnthropicService para testes
 * comparativos de variância (TCC). Utiliza a API de Chat Completions da
 * OpenAI (via OpenRouter) com Strict Structured Outputs.
 */
class OpenAiService implements PrecificacaoIaInterface
{
    use PrecificacaoIaPrompt;

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
                        'schema' => $this->responseSchemaStrict()
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

    /**
     * Variante de responseSchema() ajustada ao padrão "Strict" da OpenAI
     * (exige additionalProperties: false em cada nível e não aceita
     * minItems/maxItems/minimum/maximum em Strict Structured Outputs).
     */
    private function responseSchemaStrict(): array
    {
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

    public function identificador(): string
    {
        return 'openai';
    }
}
