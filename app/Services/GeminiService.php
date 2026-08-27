<?php

namespace App\Services;

use App\Services\Concerns\PrecificacaoIaPrompt;
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
class GeminiService implements PrecificacaoIaInterface
{
    use PrecificacaoIaPrompt;

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

    public function identificador(): string
    {
        return 'gemini';
    }
}