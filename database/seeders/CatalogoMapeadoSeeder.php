<?php

namespace Database\Seeders;

use App\Jobs\AgregarMetricasCategoria;
use App\Models\Categoria;
use App\Models\Loja;
use App\Models\SubCategoria;
use App\Models\User;
use App\Services\Camada3MercadoService;
use App\Services\OnboardingService;
use App\Services\TaxonomiaService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * CatalogoMapeadoSeeder
 *
 * Cadastra as lojas fictícias montadas a partir de catálogos públicos de lojas
 * pequenas reais (database/data/catalogos_mapeados/loja-NN.json). Cada loja
 * fictícia tem o catálogo de uma loja real: descrição limpa, sub-categoria e
 * preço praticado. O preço é o único dado real; o resto é estimado ou sintético:
 *
 *   - faturamento, custo fixo, volume e margem desejada: OnboardingService, a
 *     partir do posicionamento do JSON e de uma faixa de faturamento derivada
 *     do preço médio do catálogo (ver faixaPeloPrecoMedio);
 *   - regime `simples_nacional` e canal `loja_fisica`: o catálogo não diz o
 *     regime, e a loja virtual própria não é um canal do onboarding;
 *   - custo de cada peça: preço × (1 − margem bruta do DemoSeeder para o mesmo
 *     posicionamento) — premissa sem fonte, a mesma de premissas.php;
 *   - estoque de 1 a 10 unidades por peça e vendas de 90 dias: sorteados com
 *     semente fixa, nas faixas de premissas.php, como no DemoSeeder.
 *
 * Rodar:  php artisan db:seed --class=CatalogoMapeadoSeeder
 *
 * ─── SEGURANÇA ────────────────────────────────────────────────────────────
 * As lojas ficam com `is_demo = true` e usuário no domínio CATALOGO. O seeder
 * só apaga e recria lojas demo desse domínio: loja real e loja de outro seeder
 * nunca são tocadas. Em `production` ele se recusa a rodar sem
 * `DEMO_SEED_ALLOW=true`, e a senha tem de vir de `DEMO_PASSWORD`.
 *
 * Como o DemoSeeder, a única coisa que faz fora das próprias lojas é rodar o job
 * AgregarMetricasCategoria, que só grava métricas derivadas.
 *
 * Atenção: o DemoSeeder apaga TODA loja com is_demo = true, inclusive estas.
 * Rodar o DemoSeeder depois deste desfaz o cadastro do catálogo mapeado.
 */
class CatalogoMapeadoSeeder extends Seeder
{
    /** Domínio reservado às lojas do catálogo mapeado. É o que as identifica. */
    public const DOMINIO = 'catalogo.maremu.demo';

    /** Pasta dos JSON, relativa a database/. */
    public const PASTA = 'data/catalogos_mapeados';

    /** Semente própria: rodar duas vezes dá o mesmo estoque e as mesmas vendas. */
    private const SEMENTE = 20261113;

    /** Só fora de produção: lá a senha tem de vir de DEMO_PASSWORD. */
    private const SENHA_DESENVOLVIMENTO = 'demo12345';

    /** Padrões do onboarding para o que o catálogo não informa. */
    private const REGIME = 'simples_nacional';

    private const CANAIS = ['loja_fisica'];

    /** As faixas do onboarding, da menor para a maior. */
    private const FAIXAS = ['ate_10k', 'de_10k_a_30k', 'de_30k_a_80k', 'acima_80k'];

    /** Estoque por peça: uma variante de tamanho único. */
    private const ESTOQUE_POR_PECA = [1, 10];

    private const TAMANHO_UNICO = 'U';

    /** @var array<string, mixed> */
    private array $premissas;

    private Randomizer $sorteio;

    /** @var array<string, SubCategoria> indexado por "pai|sub" */
    private array $subCategorias = [];

    private array $filaProdutos = [];

    private array $filaVariantes = [];

    private array $filaPedidos = [];

    private array $filaItens = [];

    private int $itensVendidos = 0;

    /** @var array<int, array<string, mixed>> estimativas por loja, para o resumo */
    private array $estimativas = [];

    public function run(): void
    {
        $senha = $this->ambienteSeguro();

        $this->premissas = require database_path('data/demo/premissas.php');
        $this->sorteio = new Randomizer(new Mt19937(self::SEMENTE));
        $this->itensVendidos = 0;
        $this->estimativas = [];

        $catalogos = self::lerCatalogos();

        $this->limpar();

        app(TaxonomiaService::class)->sincronizar(app(TaxonomiaService::class)->lerArquivo());
        $this->subCategorias = SubCategoria::with('categoriaPai')->get()
            ->keyBy(fn (SubCategoria $sub) => $sub->categoriaPai->nome.'|'.$sub->nome)
            ->all();

        foreach ($catalogos as $catalogo) {
            $this->criarLoja($catalogo, $senha);
        }
        $this->descarregarFilas();

        AgregarMetricasCategoria::dispatchSync();

        $this->resumir($catalogos);
    }

    /**
     * Os JSON do catálogo mapeado, em ordem de arquivo.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lerCatalogos(): array
    {
        $arquivos = glob(database_path(self::PASTA.'/loja-*.json')) ?: [];
        sort($arquivos);

        if ($arquivos === []) {
            throw new RuntimeException('Nenhum catálogo em database/'.self::PASTA.'.');
        }

        return array_map(function (string $arquivo): array {
            $dados = json_decode((string) file_get_contents($arquivo), true, flags: JSON_THROW_ON_ERROR);

            foreach (['loja', 'nome_ficticio', 'posicionamento', 'pecas'] as $campo) {
                if (! isset($dados[$campo])) {
                    throw new RuntimeException(basename($arquivo)." sem o campo \"{$campo}\".");
                }
            }

            return $dados;
        }, $arquivos);
    }

    /** E-mail do usuário dono da loja fictícia. */
    public static function emailDaLoja(string $codigo): string
    {
        return $codigo.'@'.self::DOMINIO;
    }

    // ─────────────────────────────────────────────────────────────────────
    // SEGURANÇA
    // ─────────────────────────────────────────────────────────────────────

    private function ambienteSeguro(): string
    {
        $producao = app()->environment('production');
        $autorizado = filter_var(env('DEMO_SEED_ALLOW'), FILTER_VALIDATE_BOOLEAN);

        if ($producao && ! $autorizado) {
            throw new RuntimeException(
                'CatalogoMapeadoSeeder recusou rodar: o ambiente é "production". Este seeder apaga e '
                .'recria as lojas do catálogo mapeado (is_demo = true, domínio '.self::DOMINIO.'). Se é '
                .'mesmo isso que você quer, defina DEMO_SEED_ALLOW=true no ambiente antes de rodar.'
            );
        }

        $senha = env('DEMO_PASSWORD') ?: ($producao ? null : self::SENHA_DESENVOLVIMENTO);

        if ($senha === null) {
            throw new RuntimeException(
                'CatalogoMapeadoSeeder recusou rodar: em "production" a senha das lojas fictícias '
                .'tem de vir de DEMO_PASSWORD. Não existe senha padrão fora de desenvolvimento.'
            );
        }

        return (string) $senha;
    }

    /** Lojas do catálogo mapeado: demo e com usuário no domínio próprio. */
    private function lojasDoCatalogo(): Builder
    {
        return DB::table('lojas')
            ->join('users', 'users.id', '=', 'lojas.user_id')
            ->where('lojas.is_demo', true)
            ->where('users.email', 'like', '%@'.self::DOMINIO);
    }

    /** Apaga só as lojas do catálogo mapeado, em ordem de dependência. */
    private function limpar(): void
    {
        $usuarios = $this->lojasDoCatalogo()->pluck('lojas.user_id')->all();

        $lojas = fn () => $this->lojasDoCatalogo()->select('lojas.id');
        $produtos = fn () => DB::table('produtos')->select('id')->whereIn('loja_id', $lojas());
        $pedidos = fn () => DB::table('pedidos')->select('id')->whereIn('loja_id', $lojas());

        DB::transaction(function () use ($usuarios, $lojas, $produtos, $pedidos) {
            DB::table('itens_pedido')->whereIn('pedido_id', $pedidos())->delete();
            DB::table('itens_pedido')->whereIn('produto_id', $produtos())->delete();
            DB::table('pedidos')->whereIn('loja_id', $lojas())->delete();
            DB::table('metricas_categoria_loja')->whereIn('loja_id', $lojas())->delete();
            DB::table('logs_sugestao_ia')->whereIn('produto_id', $produtos())->delete();
            DB::table('entradas')->whereIn('loja_id', $lojas())->delete();
            DB::table('variantes_produto')->whereIn('produto_id', $produtos())->delete();
            DB::table('produtos')->whereIn('loja_id', $lojas())->delete();
            DB::table('categorias')->whereIn('loja_id', $lojas())->delete();
            DB::table('canais_venda_loja')->whereIn('loja_id', $lojas())->delete();
            DB::table('logs_onboarding_ia')->whereIn('loja_id', $lojas())->delete();

            if ($usuarios !== []) {
                DB::table('lojas')->where('is_demo', true)->whereIn('user_id', $usuarios)->delete();
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $usuarios)
                    ->delete();
                DB::table('users')->whereIn('id', $usuarios)->delete();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // LOJAS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Faixa de faturamento derivada do preço médio do catálogo, só com a lógica
     * do OnboardingService: para cada faixa, o onboarding dá um volume de vendas;
     * a faixa é consistente quando preço médio × esse volume cai nela mesma.
     * Havendo mais de uma, fica a menor (loja pequena). Sempre há ao menos uma:
     * a última faixa não tem teto.
     */
    public static function faixaPeloPrecoMedio(float $precoMedio, string $posicionamento): string
    {
        $onboarding = app(OnboardingService::class);

        foreach (self::FAIXAS as $faixa) {
            $volume = $onboarding->inferirDadosDaLoja($faixa, $posicionamento, self::REGIME, self::CANAIS)['loja']['volume_vendas_esperado'];

            if ($onboarding->faixaDoFaturamento($precoMedio * $volume) === $faixa) {
                return $faixa;
            }
        }

        return self::FAIXAS[array_key_last(self::FAIXAS)];
    }

    /** @param  array<string, mixed>  $catalogo */
    private function criarLoja(array $catalogo, string $senha): void
    {
        $posicionamento = $catalogo['posicionamento'];
        $precos = array_map(fn (array $peca) => (float) $peca['preco'], $catalogo['pecas']);
        $precoMedio = array_sum($precos) / count($precos);
        $faixa = self::faixaPeloPrecoMedio($precoMedio, $posicionamento);

        $user = User::create([
            'name' => 'Lojista '.$catalogo['nome_ficticio'],
            'email' => self::emailDaLoja($catalogo['loja']),
            'email_verified_at' => now(),
            'password' => Hash::make($senha),
        ]);

        $inferido = app(OnboardingService::class)
            ->inferirDadosDaLoja($faixa, $posicionamento, self::REGIME, self::CANAIS);

        $loja = new Loja(array_merge($inferido['loja'], [
            'user_id' => $user->id,
            'nome' => $catalogo['nome_ficticio'],
        ]));
        // Fora de $fillable: nenhuma rota marca uma loja como demonstração.
        $loja->is_demo = true;
        $loja->save();

        foreach ($inferido['canais'] as $canal) {
            $loja->canais()->create(array_merge($canal, ['ativo' => true]));
        }

        $this->estimativas[] = [
            $catalogo['loja'], $catalogo['nome_ficticio'], $posicionamento,
            number_format($precoMedio, 2, ',', '.'), $faixa,
            number_format((float) $loja->faturamento_medio_mensal, 0, ',', '.'),
            number_format((float) $loja->custo_fixo_mensal, 0, ',', '.'),
            $loja->volume_vendas_esperado,
            number_format((float) $loja->margem_lucro_desejada * 100, 0).'%',
        ];

        $margemBruta = (float) $this->premissas['margem_bruta'][$posicionamento];
        $categorias = [];

        foreach ($catalogo['pecas'] as $peca) {
            $chave = $peca['categoria_pai'].'|'.$peca['sub_categoria'];

            if (! isset($this->subCategorias[$chave])) {
                throw new RuntimeException(
                    "{$catalogo['loja']}: a sub-categoria \"{$chave}\" não existe na taxonomia."
                );
            }

            $categorias[$chave] ??= Categoria::create([
                'loja_id' => $loja->id,
                'nome' => $this->rotulo($chave),
                'sub_categoria_id' => $this->subCategorias[$chave]->id,
            ]);

            $preco = round((float) $peca['preco'], 2);
            $custo = round($preco * (1 - $margemBruta), 2);

            $produtoId = $this->registrarProduto($loja, $categorias[$chave], $peca, $preco, $custo);

            $vendas = $this->premissas['vendas_90_dias'][$chave] ?? $this->premissas['vendas_90_dias_padrao'];
            $this->registrarVendas($loja, $produtoId, $preco, $custo, $this->entre(...$vendas));
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRODUTOS E VENDAS (mesma mecânica do DemoSeeder)
    // ─────────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $peca */
    private function registrarProduto(Loja $loja, Categoria $categoria, array $peca, float $preco, float $custo): string
    {
        $id = (string) Str::uuid();
        $cadastro = now()->subDays($this->entre(...$this->premissas['idade_cadastro_dias']));
        $sequencia = count($this->filaProdutos) + 1;

        $this->filaProdutos[] = [
            'id' => $id,
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => $peca['descricao'],
            'genero' => $peca['genero'] ?? null,
            'sku' => sprintf('CM-%s-%03d', substr($loja->nome, -2), $sequencia),
            'custo_aquisicao' => $custo,
            'frete_entrada_unitario' => 0,
            'preco_piso' => null,
            'preco_venda_atual' => $preco,
            'preco_origem' => 'manual',
            'status' => 'ativo',
            'data_cadastro' => $cadastro,
            'created_at' => $cadastro,
            'updated_at' => $cadastro,
        ];

        $this->filaVariantes[] = [
            'id' => (string) Str::uuid(),
            'produto_id' => $id,
            'tamanho' => self::TAMANHO_UNICO,
            'quantidade_estoque' => $this->entre(...self::ESTOQUE_POR_PECA),
            'estoque_minimo_alerta' => 3,
            'created_at' => $cadastro,
            'updated_at' => $cadastro,
        ];

        return $id;
    }

    private function registrarVendas(Loja $loja, string $produtoId, float $preco, float $custo, int $unidades): void
    {
        $horizonte = (int) $this->premissas['horizonte_vendas_dias'];
        $restante = $unidades;

        while ($restante > 0) {
            $quantidade = min($restante, $this->entre(...$this->premissas['unidades_por_pedido']));
            $restante -= $quantidade;

            $this->registrarPedido($loja, $produtoId, $preco, $custo, $quantidade, $this->entre(0, $horizonte - 1));
        }
    }

    private function registrarPedido(Loja $loja, string $produtoId, float $preco, float $custo, int $quantidade, int $diasAtras): void
    {
        $this->itensVendidos++;
        $quando = now()->subDays($diasAtras);

        $desconto = $this->itensVendidos % (int) $this->premissas['desconto_a_cada'] === 0
            ? round($preco * $quantidade * $this->premissas['desconto_percentual'], 2)
            : 0.0;

        $pedidoId = (string) Str::uuid();

        $this->filaPedidos[] = [
            'id' => $pedidoId,
            'loja_id' => $loja->id,
            // O único canal da loja: a venda sintética não inventa outro.
            'canal_venda' => self::CANAIS[0],
            'valor_total' => round($preco * $quantidade - $desconto, 2),
            'forma_pagamento' => null,
            'data_venda' => $quando,
            'created_at' => $quando,
            'updated_at' => $quando,
        ];

        $this->filaItens[] = [
            'id' => (string) Str::uuid(),
            'pedido_id' => $pedidoId,
            'produto_id' => $produtoId,
            'variante_id' => null,
            'quantidade' => $quantidade,
            'preco_unitario_vendido' => $preco,
            'custo_unitario_no_momento' => $custo,
            'desconto_aplicado' => $desconto,
            'created_at' => $quando,
            'updated_at' => $quando,
        ];
    }

    private function descarregarFilas(): void
    {
        foreach ([
            'produtos' => $this->filaProdutos,
            'variantes_produto' => $this->filaVariantes,
            'pedidos' => $this->filaPedidos,
            'itens_pedido' => $this->filaItens,
        ] as $tabela => $linhas) {
            // Lotes pequenos: o SQLite dos testes limita os parâmetros por consulta.
            foreach (array_chunk($linhas, 50) as $lote) {
                DB::table($tabela)->insert($lote);
            }
        }

        $this->filaProdutos = $this->filaVariantes = $this->filaPedidos = $this->filaItens = [];
    }

    private function entre(int $min, int $max): int
    {
        return $this->sorteio->getInt($min, $max);
    }

    /** Nome da categoria da loja: a sub-categoria, ou o pai quando a sub é "Geral". */
    private function rotulo(string $chave): string
    {
        $sub = $this->subCategorias[$chave];

        return $sub->nome === 'Geral' ? $sub->categoriaPai->nome : $sub->nome;
    }

    // ─────────────────────────────────────────────────────────────────────
    // RESUMO
    // ─────────────────────────────────────────────────────────────────────

    /** @param  array<int, array<string, mixed>>  $catalogos */
    private function resumir(array $catalogos): void
    {
        if ($this->command === null) {
            return;
        }

        $this->command->info('Catálogo mapeado: '.count($catalogos).' lojas fictícias (is_demo, domínio '.self::DOMINIO.')');
        $this->command->line('Estimativas pelo OnboardingService (preço é o único dado real; estoque e vendas são sintéticos):');
        $this->command->table(
            ['loja', 'nome fictício', 'posicionamento', 'preço médio', 'faixa', 'faturamento', 'custo fixo', 'volume', 'margem desejada'],
            $this->estimativas,
        );

        $consulente = Loja::query()
            ->where('is_demo', true)
            ->where('posicionamento', 'medio')
            ->whereIn('user_id', User::where('email', 'like', '%@'.self::DOMINIO)->select('id'))
            ->orderBy('nome')
            ->first();

        if ($consulente === null) {
            $this->command->warn('Nenhuma loja do catálogo mapeado é "medio": não há de onde mostrar a camada 3.');

            return;
        }

        $this->command->info("Camada 3 vista da {$consulente->nome} (medio; a própria loja não entra na amostra):");

        $mercado = app(Camada3MercadoService::class);
        $chaves = [];
        foreach ($catalogos as $catalogo) {
            foreach ($catalogo['pecas'] as $peca) {
                $chaves[$peca['categoria_pai'].'|'.$peca['sub_categoria']] = true;
            }
        }
        ksort($chaves);

        $linhas = [];
        foreach (array_keys($chaves) as $chave) {
            $sub = $this->subCategorias[$chave];
            $categoria = $consulente->categorias()->where('sub_categoria_id', $sub->id)->first()
                // A consulente não vende a sub-categoria: consulta com uma categoria
                // não gravada, só para ler a faixa que ela veria se vendesse.
                ?? new Categoria(['loja_id' => $consulente->id, 'nome' => $this->rotulo($chave), 'sub_categoria_id' => $sub->id]);

            $faixa = $mercado->faixaDeMercado($consulente, $categoria);

            $linhas[] = [
                str_replace('|', ' › ', $chave),
                $faixa['disponivel'] ? $faixa['n_lojas'].' lojas' : 'indisponível',
                $faixa['disponivel']
                    ? 'R$ '.implode(' / ', array_map(fn ($v) => number_format($v, 2, ',', '.'), $faixa['preco']))
                    : $faixa['motivo'],
            ];
        }

        $this->command->table(['sub-categoria', 'amostra', 'preço p25 / mediana / p75'], $linhas);
    }
}
