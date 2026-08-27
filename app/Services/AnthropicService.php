<?php

namespace App\Services;

use App\Services\Concerns\PrecificacaoIaPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AnthropicService
 *
 * Implementação de PrecificacaoIaInterface usando a API da Anthropic
 * (Claude), como alternativa ao GeminiService. Mesmo contrato de entrada
 * (payload de PrecificacaoPayloadService::montar()) e saída (3 cenários com
 * margem_lucro_percentual) — quem chama este serviço não precisa saber qual
 * provedor está ativo.
 *
 * Não existe SDK oficial da Anthropic para PHP, então a chamada é feita via
 * HTTP client do próprio Laravel (Illuminate\Support\Facades\Http), seguindo
 * POST https://api.anthropic.com/v1/messages.
 */
class AnthropicService implements PrecificacaoIaInterface
{
    use PrecificacaoIaPrompt;

    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = (string) config('services.anthropic.key');
        $this->model  = config('services.anthropic.model', 'claude-haiku-4-5');

        if (empty($this->apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada no .env');
        }
    }

    /**
     * Envia o payload de precificação e retorna os 3 cenários já decodificados.
     *
     * Mesmo formato de saída exigido do Gemini ({"cenarios": [...]}) — o
     * prompt (systemPrompt/responseSchema, em PrecificacaoIaPrompt) é o
     * mesmo, apenas transportado de forma diferente: a Anthropic separa
     * `system` (instrução fixa) de `messages` (dados variáveis) de forma
     * mais rígida que o Gemini.
     *
     * @param  array  $payload  Saída de PrecificacaoPayloadService::montar()
     * @return array{cenarios: array<array{id: string, tipo: string, margem_lucro_percentual: float, explicacao: string}>}
     *
     * @throws RuntimeException em caso de erro de API ou resposta fora do schema
     */
    public function sugerirCenarios(array $payload): array
    {
        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout(30)
            ->retry(2, 500) // 2 tentativas extras, 500ms entre elas — mesmo padrão do GeminiService
            ->post($this->baseUrl, [
                'model'      => $this->model,
                'max_tokens' => 1024,
                'system'     => $this->systemPrompt(),
                'messages'   => [
                    ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)],
                ],
                // Força a saída a um JSON válido conforme o mesmo schema usado
                // pelo Gemini (responseSchema, em PrecificacaoIaPrompt) — a
                // Anthropic exige additionalProperties:false em cada nível.
                'output_config' => [
                    'format' => [
                        'type'   => 'json_schema',
                        'schema' => $this->responseSchemaEstrito(),
                    ],
                ],
            ]);

        if ($response->status() === 401) {
            Log::error('Falha de autenticação na API da Anthropic (chave inválida).', [
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Chave da API da Anthropic inválida ou não autorizada.');
        }

        if ($response->status() === 429) {
            Log::error('Rate limit atingido na API da Anthropic.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new RuntimeException('Limite de requisições da API da Anthropic atingido. Tente novamente em instantes.');
        }

        if ($response->failed()) {
            Log::error('Erro na API da Anthropic.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new RuntimeException(
                "Erro na API da Anthropic: {$response->status()} — {$response->body()}"
            );
        }

        $texto = data_get($response->json(), 'content.0.text');

        if (! $texto) {
            throw new RuntimeException('Resposta da Anthropic veio vazia ou em formato inesperado.');
        }

        $decodificado = json_decode($texto, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Resposta da Anthropic não é um JSON válido: ' . json_last_error_msg());
        }

        $this->validarCenarios($decodificado);

        return $decodificado;
    }

    public function identificador(): string
    {
        return 'anthropic';
    }

    /**
     * Mesmo responseSchema() de PrecificacaoIaPrompt, adaptado para o modo
     * estrito exigido por output_config.format da Anthropic:
     * - additionalProperties: false em cada nível do objeto;
     * - não aceita minItems/maxItems em propriedades do tipo 'array';
     * - não aceita minimum/maximum em propriedades do tipo 'number'
     *   (400 invalid_request_error se presentes em qualquer um desses casos).
     * Essas checagens continuam garantidas em runtime por validarCenarios()
     * — o schema aqui só perde a validação estrutural, não a regra de
     * negócio em si (exatamente 3 cenários, margem entre 0.0 e 0.80). O
     * conteúdo das regras (campos, enums) é idêntico ao do Gemini — só a
     * forma exigida pelo provedor muda.
     */
    private function responseSchemaEstrito(): array
    {
        $schema = $this->responseSchema();
        $schema['additionalProperties'] = false;
        unset($schema['properties']['cenarios']['minItems'], $schema['properties']['cenarios']['maxItems']);

        $itemProperties = &$schema['properties']['cenarios']['items']['properties'];
        unset($itemProperties['margem_lucro_percentual']['minimum'], $itemProperties['margem_lucro_percentual']['maximum']);
        unset($itemProperties);

        $schema['properties']['cenarios']['items']['additionalProperties'] = false;

        return $schema;
    }
}
