<?php

namespace App\Services;

use RuntimeException;

/**
 * PrecificacaoIaSimuladaService
 *
 * Provedor de precificação SEM IA, para testes e para verificar o fluxo
 * localmente sem chamar nenhum provedor externo. Ativado com
 * IA_PROVIDER=simulado.
 *
 * Devolve sempre as mesmas três margens, na ordem convencional do prompt
 * (cenario_1 liquidação, cenario_2 ideal, cenario_3 alta demanda), e marca
 * cada explicação como simulada. Não lê o payload: o prompt e o payload
 * continuam sendo montados pelo controller exatamente como antes.
 *
 * Recusa rodar em produção: lá uma sugestão simulada seria mostrada ao
 * lojista como se fosse a da IA.
 */
class PrecificacaoIaSimuladaService implements PrecificacaoIaInterface
{
    /** Margens líquidas fixas por cenário. */
    public const MARGENS = [
        'cenario_1' => ['tipo' => 'liquidacao', 'margem' => 0.08],
        'cenario_2' => ['tipo' => 'ideal', 'margem' => 0.18],
        'cenario_3' => ['tipo' => 'alta_demanda', 'margem' => 0.30],
    ];

    private const EXPLICACAO = [
        'liquidacao' => 'Margem mais enxuta para girar o estoque.',
        'ideal' => 'Margem equilibrada para a venda do dia a dia.',
        'alta_demanda' => 'Margem mais folgada para quando a peça tem procura.',
    ];

    public function __construct()
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'IA_PROVIDER=simulado recusou rodar: o ambiente é "production". '
                .'O provedor simulado serve só para testes e desenvolvimento.'
            );
        }
    }

    public function sugerirCenarios(array $payload): array
    {
        $cenarios = [];

        foreach (self::MARGENS as $id => ['tipo' => $tipo, 'margem' => $margem]) {
            $cenarios[] = [
                'id' => $id,
                'tipo' => $tipo,
                'margem_lucro_percentual' => $margem,
                'explicacao' => '[simulado] '.self::EXPLICACAO[$tipo],
            ];
        }

        return ['cenarios' => $cenarios];
    }

    public function identificador(): string
    {
        return 'simulado';
    }
}
