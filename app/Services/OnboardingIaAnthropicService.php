<?php

namespace App\Services;

use App\Services\Concerns\OnboardingIaPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * OnboardingIaAnthropicService
 *
 * Implementação de OnboardingIaInterface usando a API da Anthropic (Claude),
 * como alternativa ao OnboardingIaGeminiService. Mesmo contrato de entrada
 * (texto dissertativo + dados factuais) e saída (estimativas + canais
 * sugeridos) — quem chama este serviço não precisa saber qual provedor está
 * ativo. Mesmo padrão já usado por AnthropicService (precificação).
 *
 * Não existe SDK oficial da Anthropic para PHP, então a chamada é feita via
 * HTTP client do próprio Laravel (Illuminate\Support\Facades\Http), seguindo
 * POST https://api.anthropic.com/v1/messages.
 */
class OnboardingIaAnthropicService implements OnboardingIaInterface
{
    use OnboardingIaPrompt;

    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct(private readonly OnboardingGuardrail $guardrail)
    {
        $this->apiKey = (string) config('services.anthropic.key');
        $this->model  = config('services.anthropic.model', 'claude-haiku-4-5');

        if (empty($this->apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY não configurada no .env');
        }
    }

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
    public function estimarDadosLoja(string $textoDescritivo, array $dadosFactuais): array
    {
        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout(45) // mesmo timeout generoso do OnboardingIaGeminiService — latência de LLM é variável em produção
            ->retry(2, 1500)
            ->post($this->baseUrl, [
                'model'       => $this->model,
                'max_tokens'  => 2048,
                'temperature' => 0.0, // baixa: mesmo padrão do OnboardingIaGeminiService — consistência, não criatividade
                'system'      => $this->systemPrompt(),
                'messages'    => [
                    ['role' => 'user', 'content' => json_encode([
                        'texto_do_lojista' => $textoDescritivo,
                        'dados_factuais'   => $dadosFactuais,
                    ], JSON_UNESCAPED_UNICODE)],
                ],
                // Força a saída a um JSON válido conforme o mesmo schema usado
                // pelo Gemini (responseSchema, em OnboardingIaPrompt) — a
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

        return $this->processarResposta($decodificado, $dadosFactuais);
    }

    public function identificador(): string
    {
        return 'anthropic';
    }

    /**
     * Mesmo responseSchema() de OnboardingIaPrompt, adaptado para o modo
     * estrito exigido por output_config.format da Anthropic:
     * - additionalProperties: false em cada nível do objeto;
     * - não aceita minimum/maximum em propriedades do tipo 'number'/'integer'
     *   (400 invalid_request_error se presentes) — mesma adaptação já feita
     *   em AnthropicService (precificação). O clamping de negócio continua
     *   garantido em runtime por OnboardingGuardrail::clampar(), então o
     *   schema aqui só perde a validação estrutural, não a regra em si.
     */
    private function responseSchemaEstrito(): array
    {
        $schema = $this->responseSchema();
        $schema['additionalProperties'] = false;

        $schema['properties']['posicionamento']['additionalProperties'] = false;

        foreach (['faturamento_medio_mensal', 'custo_fixo_mensal', 'margem_lucro_desejada', 'volume_vendas_esperado'] as $campo) {
            unset($schema['properties'][$campo]['properties']['valor']['minimum'], $schema['properties'][$campo]['properties']['valor']['maximum']);
            $schema['properties'][$campo]['additionalProperties'] = false;
        }

        return $schema;
    }
}
