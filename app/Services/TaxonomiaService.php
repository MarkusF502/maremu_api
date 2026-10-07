<?php

namespace App\Services;

use App\Models\CategoriaPai;
use App\Models\SubCategoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sincroniza a taxonomia fixa a partir de database/data/taxonomia.json.
 *
 * É idempotente e nunca apaga: identifica cada registro pelo slug, cria o
 * que falta e atualiza nome, ordem e id do Google. Assim pode rodar em
 * produção a cada deploy sem quebrar categorias já classificadas.
 */
class TaxonomiaService
{
    /**
     * @param  array<int, array{nome: string, google_taxonomy_id?: ?int, sub_categorias: array<int, array{nome: string, google_taxonomy_id?: ?int, comparavel?: bool}>}>  $taxonomia
     * @return array{categorias_pai: int, sub_categorias: int}
     */
    public function sincronizar(array $taxonomia): array
    {
        $subs = 0;

        DB::transaction(function () use ($taxonomia, &$subs) {
            foreach (array_values($taxonomia) as $ordemPai => $pai) {
                if (empty($pai['nome']) || empty($pai['sub_categorias'])) {
                    throw new InvalidArgumentException('Cada categoria-pai precisa de nome e de ao menos uma sub-categoria.');
                }

                $registroPai = CategoriaPai::updateOrCreate(
                    ['slug' => Str::slug($pai['nome'])],
                    [
                        'nome' => $pai['nome'],
                        'google_taxonomy_id' => $pai['google_taxonomy_id'] ?? null,
                        'ordem' => $ordemPai,
                    ]
                );

                foreach (array_values($pai['sub_categorias']) as $ordemSub => $sub) {
                    if (empty($sub['nome'])) {
                        throw new InvalidArgumentException("Sub-categoria sem nome em \"{$pai['nome']}\".");
                    }

                    SubCategoria::updateOrCreate(
                        ['categoria_pai_id' => $registroPai->id, 'slug' => Str::slug($sub['nome'])],
                        [
                            'nome' => $sub['nome'],
                            'google_taxonomy_id' => $sub['google_taxonomy_id'] ?? null,
                            'comparavel' => $sub['comparavel'] ?? true,
                            'ordem' => $ordemSub,
                        ]
                    );
                    $subs++;
                }
            }
        });

        return ['categorias_pai' => count($taxonomia), 'sub_categorias' => $subs];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function lerArquivo(?string $caminho = null): array
    {
        $caminho ??= database_path('data/taxonomia.json');

        if (! is_file($caminho)) {
            throw new InvalidArgumentException("Arquivo de taxonomia não encontrado: {$caminho}");
        }

        $dados = json_decode((string) file_get_contents($caminho), true);

        if (! is_array($dados)) {
            throw new InvalidArgumentException('O arquivo de taxonomia não é um JSON válido.');
        }

        return $dados;
    }
}
