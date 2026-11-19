<?php

namespace App\Console\Commands;

use App\Services\RecomendacaoCenarioDados;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Taxa de aceitação da recomendação de cenário, por versão das regras e
 * origem da referência.
 *
 * Cada linha de logs_recomendacao_cenario aponta para a rodada de sugestão
 * em que foi exibida. A escolha do lojista está nessa mesma rodada
 * (logs_sugestao_ia.cenario_escolhido), e o tipo do cenário escolhido é lido
 * dos cenarios_retornados DELA: o vínculo cenario_N ↔ tipo não é fixo.
 */
class AceitacaoRecomendacaoCenario extends Command
{
    protected $signature = 'recomendacao:aceitacao {--loja= : id de uma loja, para ver só ela}';

    protected $description = 'Mostra quantas recomendações de cenário houve, quantas tiveram escolha registrada e quantas coincidiram com a escolha.';

    public function handle(): int
    {
        $loja = $this->option('loja');

        if ($loja !== null && ! DB::table('lojas')->where('id', $loja)->exists()) {
            $this->error("Loja {$loja} não encontrada.");

            return self::FAILURE;
        }

        $linhas = DB::table('logs_recomendacao_cenario as r')
            ->leftJoin('logs_sugestao_ia as s', 's.id', '=', 'r.log_sugestao_ia_id')
            ->when($loja !== null, fn ($q) => $q->where('r.loja_id', $loja))
            ->get(['r.versao_regras', 'r.origem_recomendacao', 'r.cenario_recomendado', 's.cenario_escolhido', 's.cenarios_retornados']);

        if ($linhas->isEmpty()) {
            $this->info('Nenhuma recomendação registrada'.($loja !== null ? ' para esta loja.' : '.'));

            return self::SUCCESS;
        }

        $grupos = [];
        foreach ($linhas as $linha) {
            $chave = $linha->versao_regras.'|'.$linha->origem_recomendacao;
            $grupos[$chave] ??= [
                'versao' => $linha->versao_regras,
                'origem' => $linha->origem_recomendacao,
                'registros' => 0, 'recomendacoes' => 0, 'com_escolha' => 0, 'coincidiram' => 0,
            ];
            $g = &$grupos[$chave];
            $g['registros']++;

            if ($linha->cenario_recomendado === null) {
                unset($g);

                continue; // indisponível: nada para aceitar
            }

            $g['recomendacoes']++;

            if ($linha->cenario_escolhido !== null) {
                $g['com_escolha']++;

                $tipoEscolhido = $linha->cenario_escolhido === 'manual'
                    ? null
                    : RecomendacaoCenarioDados::tipoDoCenario($linha->cenarios_retornados, $linha->cenario_escolhido);

                if ($tipoEscolhido === $linha->cenario_recomendado) {
                    $g['coincidiram']++;
                }
            }
            unset($g);
        }

        ksort($grupos);

        $this->table(
            ['versão', 'origem', 'registros', 'recomendações', 'com escolha', 'coincidiram', 'aceitação'],
            array_map(fn (array $g) => [
                $g['versao'],
                $g['origem'],
                $g['registros'],
                $g['recomendacoes'],
                $g['com_escolha'],
                $g['coincidiram'],
                $g['com_escolha'] > 0
                    ? number_format(100 * $g['coincidiram'] / $g['com_escolha'], 1, ',', '.').'%'
                    : '—',
            ], array_values($grupos)),
        );

        $this->line('Aceitação = coincidiram ÷ com escolha. Registros inclui as rodadas sem recomendação (indisponível).');

        return self::SUCCESS;
    }
}
