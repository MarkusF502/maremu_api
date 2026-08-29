<?php

namespace App\Services;

use App\Services\Concerns\OnboardingIaPrompt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OnboardingIaGeminiService
 *
 * Implementação de OnboardingIaInterface usando a API do Gemini. Recebe o
 * texto dissertativo da Tela 2 + os dados factuais da Tela 1, chama o
 * Gemini, valida/clampa o resultado contra os guardrails de negócio e
 * devolve estimativas estruturadas prontas para a Tela 3.
 *
 * Chamada própria ao Gemini, separada do GeminiService de precificação —
 * ver SPEC §4.4 (prompts/schemas com propósitos totalmente diferentes).
 *
 * Nunca grava nada no banco (SPEC D1) — quem persiste é o Controller.
 */
class OnboardingIaGeminiService implements OnboardingIaInterface
{
    use OnboardingIaPrompt;

    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(private readonly OnboardingGuardrail $guardrail)
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->model  = config('services.gemini.model', 'gemini-2.5-flash');

        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY não configurada no .env');
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
        // 45s + backoff de 1.5s: o modelo por trás de 'gemini-flash-latest'
        // tem mostrado latência bem variável em produção (2s a 20s+ para o
        // mesmo prompt trivial, e ocasionalmente 503 "high demand") — ver
        // investigação de timeout em app/Services/OnboardingIaGeminiService.php.
        // Um timeout de 30s some vezes cortava respostas que só estavam
        // lentas, não travadas; e retry imediato (500ms) não ajuda contra
        // sobrecarga do lado do provedor. Se mesmo assim estourar, o
        // RuntimeException é capturado pelo controller e cai no fallback
        // determinístico (Tela 2B) — rede de segurança por design (SPEC D5).
        $response = Http::timeout(45)
            ->retry(2, 1500)
            ->post("{$this->baseUrl}/{$this->model}:generateContent?key={$this->apiKey}", [
                'systemInstruction' => [
                    'parts' => [['text' => $this->systemPrompt()]],
                ],
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [['text' => json_encode([
                            'texto_do_lojista' => $textoDescritivo,
                            'dados_factuais'   => $dadosFactuais,
                        ], JSON_UNESCAPED_UNICODE)]],
                    ],
                ],
                'generationConfig' => [
                    'temperature'      => 0.0,
                    'responseMimeType' => 'application/json',
                    'responseSchema'   => $this->responseSchema(),
                    // Extração de dados estruturados não precisa de chain-of-thought.
                    // Sem isso, aliases como 'gemini-flash-latest' podem resolver pra
                    // um modelo com "thinking" ligado por padrão (visto em produção:
                    // gemini-3.7-flash gastando ~100 tokens de raciocínio até numa
                    // chamada trivial) — o que empurra a latência pra perto/acima dos
                    // 30s de timeout num prompt do tamanho do onboarding. thinkingBudget
                    // 0 mantém a chamada rápida, coerente com D2 (tarefa simples).
                    'thinkingConfig' => ['thinkingBudget' => 0],
                ],
                // Sem 'tools' — nunca dá function calling pra essa chamada (SPEC S4).
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

        return $this->processarResposta($decodificado, $dadosFactuais);
    }

    public function identificador(): string
    {
        return 'gemini';
    }
}
