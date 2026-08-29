<?php

namespace App\Services;

/**
 * OnboardingGuardrail
 *
 * Regras determinísticas que cercam a IA por todos os lados (ver SPEC §8):
 *
 *  - texto suficiente para valer a pena gastar uma chamada de API (pré-API)
 *  - clamping de valores numéricos estimados pela IA para os ranges
 *    aceitáveis de negócio (pós-API)
 *
 * Extraído como classe própria (em vez de método privado do
 * OnboardingIaGeminiService/OnboardingIaAnthropicService) para ser testável
 * isoladamente, sem HTTP nem mocks —
 * ver SPEC §11.1 (OnboardingGuardrailTest / OnboardingGuardrailClampTest).
 */
class OnboardingGuardrail
{
    private const MIN_CARACTERES = 50;
    private const MIN_PALAVRAS_DISTINTAS = 5;

    /**
     * Ranges aceitáveis por campo estimável. Usados tanto para clamping
     * quanto, na SPEC, como referência para o responseSchema do Gemini.
     */
    public const RANGES = [
        'faturamento_medio_mensal' => ['min' => 1000,  'max' => 500000],
        'custo_fixo_mensal'        => ['min' => 200,   'max' => 100000],
        'margem_lucro_desejada'    => ['min' => 0.05,  'max' => 0.60],
        'volume_vendas_esperado'   => ['min' => 10,    'max' => 5000],
    ];

    /**
     * Guardrail determinístico pré-API (SPEC §6.2 passo 2 / §10).
     *
     * Verifica se o texto tem conteúdo mínimo antes de gastar uma chamada
     * ao Gemini. O tamanho mínimo (50 chars) já é coberto pela validação do
     * FormRequest — este método cobre os dois sinais adicionais que a
     * validação de tamanho sozinha não pega: repetição de caractere e
     * pouca variedade de palavras (ex: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
     * ou "loja loja loja loja loja loja loja loja loja loja loja loja").
     */
    public function textoSuficiente(string $texto): bool
    {
        $texto = trim($texto);

        if (mb_strlen($texto) < self::MIN_CARACTERES) {
            return false;
        }

        if ($this->ehRepeticaoDeCaractere($texto)) {
            return false;
        }

        return $this->contarPalavrasDistintas($texto) >= self::MIN_PALAVRAS_DISTINTAS;
    }

    /**
     * Detecta texto dominado por um único caractere repetido (ex: "aaaa...",
     * "!!!!!!...", com ou sem espaços intercalados).
     */
    private function ehRepeticaoDeCaractere(string $texto): bool
    {
        $semEspacos = preg_replace('/\s+/u', '', $texto);

        if ($semEspacos === '' || $semEspacos === null) {
            return true;
        }

        // Se o caractere mais frequente domina > 60% do texto sem espaços,
        // trata como ruído — texto real não se comporta assim.
        $contagens = array_count_values(mb_str_split($semEspacos));
        $maxContagem = max($contagens);

        return ($maxContagem / mb_strlen($semEspacos)) > 0.6;
    }

    private function contarPalavrasDistintas(string $texto): int
    {
        $palavras = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($texto), -1, PREG_SPLIT_NO_EMPTY);

        return count(array_unique($palavras ?: []));
    }

    /**
     * Aplica o clamping de range (SPEC §5.2) a um valor numérico estimado
     * pela IA para um campo específico.
     *
     * - dentro do range              → usa como está, clampado=false
     * - fora, mas até 3x a distância → clampa pro limite mais próximo, clampado=true
     * - absurdamente fora (>3x)      → null (campo fica em branco pro lojista preencher)
     *
     * @return array{valor: int|float|null, clampado: bool}
     */
    public function clampar(string $campo, int|float $valor): array
    {
        $range = self::RANGES[$campo]
            ?? throw new \InvalidArgumentException("[OnboardingGuardrail] Campo sem range definido: '{$campo}'");

        $min = $range['min'];
        $max = $range['max'];

        if ($valor >= $min && $valor <= $max) {
            return ['valor' => $valor, 'clampado' => false];
        }

        $distanciaLimite = $valor < $min ? $min - $valor : $valor - $max;
        $amplitude = $max - $min;

        // "até 3x a distância do limite" — usamos a amplitude do range como
        // unidade de distância, para o limite absurdo escalar com o campo.
        if ($distanciaLimite <= $amplitude * 3) {
            $clampado = $valor < $min ? $min : $max;

            return ['valor' => $clampado, 'clampado' => true];
        }

        return ['valor' => null, 'clampado' => false];
    }
}
