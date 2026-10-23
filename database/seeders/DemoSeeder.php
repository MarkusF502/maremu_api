<?php

namespace Database\Seeders;

use App\Jobs\AgregarMetricasCategoria;
use App\Models\Categoria;
use App\Models\Loja;
use App\Models\SubCategoria;
use App\Models\User;
use App\Services\Camada3MercadoService;
use App\Services\OnboardingService;
use App\Services\PricingEngine;
use App\Services\TaxonomiaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * DemoSeeder
 *
 * Monta o cenário de demonstração da camada 3: uma loja consulente e um
 * conjunto de lojas semelhantes, com catálogo, estoque e vendas simuladas.
 * Todos os números saem de database/data/demo/premissas.php, com semente fixa:
 * rodar duas vezes dá exatamente o mesmo resultado.
 *
 * Rodar:  php artisan db:seed --class=DemoSeeder
 *
 * Não está pendurado no DatabaseSeeder de propósito: deploy não deve criar
 * dado de demonstração sem alguém pedir.
 *
 * ─── SEGURANÇA ────────────────────────────────────────────────────────────
 * Mexe só em lojas com `is_demo = true`: apaga essas e recria. Loja real,
 * com seus produtos, pedidos e métricas, nunca é tocada. Em `production` o
 * seeder se recusa a rodar sem `DEMO_SEED_ALLOW=true` no ambiente.
 *
 * A única coisa que o seeder faz fora do mundo demo é rodar, no final, o job
 * AgregarMetricasCategoria — o mesmo do cron diário. Ele recalcula a janela de
 * métricas de todas as lojas, inclusive as reais, mas não altera cadastro,
 * produto, estoque nem venda de ninguém: só grava métricas derivadas.
 *
 * ─── DISTRIBUIÇÃO DAS LOJAS ───────────────────────────────────────────────
 * A consulente é `medio`, faixa `de_10k_a_30k`. São 27 lojas semelhantes:
 * 15 `medio` (6 na faixa da consulente, 9 em outras faixas), 6 `popular` e
 * 6 `premium`. Cada uma vende um subconjunto das sub-categorias, escolhido
 * para que cada regra da camada 3 tenha um caso que a demonstre:
 *
 *   Sub-categoria                 | lojas medio | na faixa | resultado esperado
 *   ------------------------------|-------------|----------|-------------------------------
 *   Camisetas e tops › Geral      |     12      |    6     | disponível, estreita pela faixa
 *   Calças › Geral                |     12      |    3     | disponível, só posicionamento
 *   Vestidos › Geral              |      5      |    2     | disponível, n_lojas 5
 *   Casacos › Casacos e jaquetas  |      4      |    2     | amostra_insuficiente
 *   Roupas íntimas e meias › Sutiãs |    5      |    3     | disponível
 *   Roupas íntimas e meias › Meias  |    5      |    3     | disponível
 *
 * Camisetas estreita porque 12 > 10 e sobram 6 ≥ 5 na faixa; Calças não
 * estreita porque sobrariam 3 < 5. A consulente tem ainda uma categoria em
 * "Casacos › Outros" (não comparável) e um bazar sem classificação.
 *
 * As 6 lojas `popular` e as 6 `premium` vendem as seis sub-categorias, para
 * que trocar o posicionamento da consulente também produza faixas
 * disponíveis — inclusive Casacos, que em `medio` é insuficiente.
 *
 * Uma das lojas `medio` da faixa (M04) é especializada em roupa íntima: 4 das
 * 6 categorias dela são de lingerie ou complementares, o que deixa mais da
 * metade do catálogo nesse grupo sem tirá-la da amostra de Sutiãs.
 */
class DemoSeeder extends Seeder
{
    public const NOME_CONSULENTE = 'Loja Demo Apresentação';

    public const EMAIL_CONSULENTE = 'apresentacao@maremu.demo';

    /**
     * Loja especializada em roupa íntima — a M04 da tabela acima. Fica exposta
     * por constante porque é um caso avaliado, não uma loja qualquer.
     */
    public const EMAIL_ESPECIALISTA = 'demo-04@maremu.demo';

    /** Domínio reservado às lojas de demonstração. */
    private const DOMINIO = 'maremu.demo';

    /** Só fora de produção: lá a senha tem de vir de DEMO_PASSWORD. */
    private const SENHA_DESENVOLVIMENTO = 'demo12345';

    // Chaves das sub-categorias, no formato "categoria-pai|sub-categoria".
    private const CALCAS = 'Calças|Geral';
    private const CAMISETAS = 'Camisetas e tops|Geral';
    private const VESTIDOS = 'Vestidos|Geral';
    private const CASACOS = 'Casacos|Casacos e jaquetas';
    private const SUTIAS = 'Roupas íntimas e meias|Sutiãs';
    private const MEIAS = 'Roupas íntimas e meias|Meias';
    private const LINGERIE = 'Roupas íntimas e meias|Lingerie';
    private const MEIAS_CALCAS = 'Roupas íntimas e meias|Meias-calças';
    private const ROUPAS_INTIMAS = 'Roupas íntimas e meias|Roupas íntimas';
    private const NAO_COMPARAVEL = 'Casacos|Outros';

    /** Pseudo-chave: o bazar não tem sub-categoria, só nome e preço próprios. */
    private const BAZAR = 'bazar';

    /** As seis sub-categorias avaliadas, vendidas por toda loja de catálogo amplo. */
    private const CATALOGO_AMPLO = [
        self::CAMISETAS, self::CALCAS, self::VESTIDOS, self::CASACOS, self::SUTIAS, self::MEIAS,
    ];

    /**
     * Lojas semelhantes: [posicionamento, faixa de faturamento, sub-categorias].
     * A ordem é a do comentário da classe — mexer aqui muda as contagens.
     *
     * @var array<int, array{0: string, 1: string, 2: string[]}>
     */
    private const LOJAS_SEMELHANTES = [
        // ── medio, faixa de_10k_a_30k (a mesma da consulente): 6 lojas ──────
        ['medio', 'de_10k_a_30k', [self::CAMISETAS, self::CALCAS, self::VESTIDOS, self::CASACOS]],
        ['medio', 'de_10k_a_30k', [self::CAMISETAS, self::CALCAS, self::VESTIDOS, self::CASACOS]],
        ['medio', 'de_10k_a_30k', [self::CAMISETAS, self::CALCAS]],
        // M04 — especializada em roupa íntima
        ['medio', 'de_10k_a_30k', [self::CAMISETAS, self::SUTIAS, self::MEIAS, self::LINGERIE, self::MEIAS_CALCAS, self::ROUPAS_INTIMAS]],
        ['medio', 'de_10k_a_30k', [self::CAMISETAS, self::SUTIAS, self::MEIAS]],
        ['medio', 'de_10k_a_30k', [self::CAMISETAS, self::SUTIAS, self::MEIAS]],

        // ── medio, outras faixas: 9 lojas ───────────────────────────────────
        ['medio', 'ate_10k', [self::CAMISETAS, self::CALCAS, self::VESTIDOS, self::CASACOS]],
        ['medio', 'ate_10k', [self::CAMISETAS, self::CALCAS, self::VESTIDOS, self::CASACOS]],
        ['medio', 'ate_10k', [self::CAMISETAS, self::CALCAS, self::VESTIDOS]],
        ['medio', 'de_30k_a_80k', [self::CAMISETAS, self::CALCAS, self::SUTIAS, self::MEIAS]],
        ['medio', 'de_30k_a_80k', [self::CAMISETAS, self::CALCAS, self::SUTIAS, self::MEIAS]],
        ['medio', 'de_30k_a_80k', [self::CAMISETAS, self::CALCAS]],
        ['medio', 'acima_80k', [self::CALCAS]],
        ['medio', 'acima_80k', [self::CALCAS]],
        ['medio', 'acima_80k', [self::CALCAS]],

        // ── popular: 6 lojas de catálogo amplo ──────────────────────────────
        ['popular', 'ate_10k', self::CATALOGO_AMPLO],
        ['popular', 'ate_10k', self::CATALOGO_AMPLO],
        ['popular', 'de_10k_a_30k', self::CATALOGO_AMPLO],
        ['popular', 'de_10k_a_30k', self::CATALOGO_AMPLO],
        ['popular', 'de_10k_a_30k', self::CATALOGO_AMPLO],
        ['popular', 'de_30k_a_80k', self::CATALOGO_AMPLO],

        // ── premium: 6 lojas de catálogo amplo ──────────────────────────────
        ['premium', 'de_10k_a_30k', self::CATALOGO_AMPLO],
        ['premium', 'de_10k_a_30k', self::CATALOGO_AMPLO],
        ['premium', 'de_10k_a_30k', self::CATALOGO_AMPLO],
        ['premium', 'de_30k_a_80k', self::CATALOGO_AMPLO],
        ['premium', 'de_30k_a_80k', self::CATALOGO_AMPLO],
        ['premium', 'acima_80k', self::CATALOGO_AMPLO],
    ];

    /** Canais de venda, alternados entre as lojas para variar a taxa. */
    private const CANAIS_POR_PERFIL = [
        ['loja_fisica'],
        ['loja_fisica', 'instagram_whatsapp'],
        ['instagram_whatsapp', 'marketplace'],
    ];

    /** Nome comercial das lojas semelhantes, por posicionamento. */
    private const NOMES_DE_LOJA = [
        'popular' => ['Bazar', 'Pronta Entrega', 'Fábrica', 'Outlet', 'Atacado', 'Popular'],
        'medio' => ['Boutique', 'Ateliê', 'Casa', 'Coleção', 'Studio', 'Moda'],
        'premium' => ['Maison', 'Griffe', 'Alta Costura', 'Signature', 'Couture', 'Private'],
    ];

    /** Nome dos produtos por sub-categoria. */
    private const NOMES_DE_PRODUTO = [
        self::CAMISETAS => ['Camiseta básica', 'Blusa canelada', 'Cropped', 'Regata', 'T-shirt estampada'],
        self::CALCAS => ['Calça jeans', 'Calça alfaiataria', 'Calça wide leg', 'Calça cargo', 'Calça skinny'],
        self::VESTIDOS => ['Vestido midi', 'Vestido longo', 'Vestido tubinho', 'Vestido chemise', 'Vestido de alcinha'],
        self::CASACOS => ['Jaqueta jeans', 'Casaco de lã', 'Blazer', 'Parka', 'Jaqueta puffer'],
        self::SUTIAS => ['Sutiã triângulo', 'Sutiã com bojo', 'Top sutiã', 'Sutiã meia taça', 'Sutiã strappy'],
        self::MEIAS => ['Meia soquete', 'Meia cano alto', 'Meia invisível', 'Kit de meias', 'Meia canelada'],
        self::LINGERIE => ['Conjunto de renda', 'Body de renda', 'Camisola curta'],
        self::MEIAS_CALCAS => ['Meia-calça fio 40', 'Meia-calça opaca', 'Meia-calça arrastão'],
        self::ROUPAS_INTIMAS => ['Calcinha tanga', 'Calcinha fio', 'Kit de calcinhas'],
        self::NAO_COMPARAVEL => ['Gorro de lã', 'Luva de tricô', 'Cachecol'],
        self::BAZAR => ['Peça de ponta de estoque', 'Saldo de coleção', 'Achadinho'],
    ];

    private const CORES = ['preto', 'branco', 'areia', 'off white', 'verde musgo', 'vinho', 'jeans claro'];

    /** @var array<string, mixed> */
    private array $premissas;

    private Randomizer $sorteio;

    /** @var array<string, SubCategoria> indexado por "pai|sub" */
    private array $subCategorias = [];

    /** @var array<string, array<string, float>> preço base por "pai|sub" e posicionamento */
    private array $precoBase = [];

    /** Filas de inserção em massa, descarregadas em lotes no final. */
    private array $filaProdutos = [];

    private array $filaVariantes = [];

    private array $filaPedidos = [];

    private array $filaItens = [];

    /** Contador de itens vendidos, para aplicar desconto a cada N. */
    private int $itensVendidos = 0;

    /** @var array<string, float> fator de preço por loja, indexado pelo id */
    private array $fatorPreco = [];

    /** @var array<int, array<string, mixed>> resultado das sondas de piso, para o resumo */
    private array $sondas = [];

    public function run(): void
    {
        $senha = $this->ambienteSeguro();

        $this->premissas = require database_path('data/demo/premissas.php');
        $this->sorteio = new Randomizer(new Mt19937($this->premissas['semente']));

        $this->limparDemo();

        // Idempotente e nunca destrutiva: garante que a taxonomia fixa existe
        // mesmo num banco recém-criado.
        app(TaxonomiaService::class)->sincronizar(app(TaxonomiaService::class)->lerArquivo());
        $this->carregarTaxonomia();

        $consulente = $this->criarConsulente($senha);
        $this->criarLojasSemelhantes($senha);
        $this->descarregarFilas();

        AgregarMetricasCategoria::dispatchSync();

        // As sondas de piso precisam da faixa já calculada: o custo de cada uma
        // é resolvido a partir do p25/mediana/p75 que o job acabou de produzir.
        // A consulente não entra na própria amostra, então criar produtos nela
        // não move a faixa — a segunda passada só atualiza as métricas dela.
        $this->criarSondasDePiso($consulente);
        $this->descarregarFilas();

        AgregarMetricasCategoria::dispatchSync();

        $this->resumir($consulente);
    }

    // ─────────────────────────────────────────────────────────────────────
    // SEGURANÇA
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Recusa rodar onde não deve e devolve a senha das lojas de demonstração.
     */
    private function ambienteSeguro(): string
    {
        $producao = app()->environment('production');

        // env() converte a string "true" para booleano, então a comparação
        // passa pelo filter_var: aceita true, "true", "1" e "yes" do mesmo jeito.
        $autorizado = filter_var(env('DEMO_SEED_ALLOW'), FILTER_VALIDATE_BOOLEAN);

        if ($producao && ! $autorizado) {
            throw new RuntimeException(
                'DemoSeeder recusou rodar: o ambiente é "production". Este seeder apaga e '
                .'recria todas as lojas com is_demo = true. Se é mesmo isso que você quer, '
                .'defina DEMO_SEED_ALLOW=true no ambiente antes de rodar.'
            );
        }

        $senha = env('DEMO_PASSWORD') ?: ($producao ? null : self::SENHA_DESENVOLVIMENTO);

        if ($senha === null) {
            throw new RuntimeException(
                'DemoSeeder recusou rodar: em "production" a senha das lojas de demonstração '
                .'tem de vir de DEMO_PASSWORD. Não existe senha padrão fora de desenvolvimento.'
            );
        }

        return (string) $senha;
    }

    /**
     * Apaga só o que pertence a loja de demonstração, em ordem de dependência.
     *
     * As subconsultas são refeitas a cada passo (e não uma lista de ids) para
     * não estourar o limite de parâmetros do banco quando a demo crescer.
     */
    private function limparDemo(): void
    {
        $this->fatorPreco = [];
        $this->sondas = [];

        $usuarios = DB::table('lojas')->where('is_demo', true)->pluck('user_id')->all();

        $lojas = fn () => DB::table('lojas')->select('id')->where('is_demo', true);
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
            DB::table('lojas')->where('is_demo', true)->delete();

            if ($usuarios !== []) {
                DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $usuarios)
                    ->delete();
                DB::table('users')->whereIn('id', $usuarios)->delete();
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // TAXONOMIA E PREÇOS
    // ─────────────────────────────────────────────────────────────────────

    private function carregarTaxonomia(): void
    {
        $this->subCategorias = SubCategoria::with('categoriaPai')->get()
            ->keyBy(fn (SubCategoria $sub) => $sub->categoriaPai->nome.'|'.$sub->nome)
            ->all();

        $linhas = array_merge($this->premissas['precos_base'], $this->premissas['precos_premissa']);

        foreach ($linhas as $linha) {
            $chave = $linha['pai'].'|'.$linha['sub'];

            if (! isset($this->subCategorias[$chave])) {
                throw new RuntimeException(
                    "A premissa de preço aponta para \"{$chave}\", que não existe na taxonomia. "
                    .'Confira os nomes em database/data/taxonomia.json.'
                );
            }

            $this->precoBase[$chave] = [
                'popular' => $linha['popular'],
                'medio' => $linha['medio'],
                'premium' => $linha['premium'],
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // LOJAS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Loja consulente: a que o avaliador usa para ver a camada 3.
     *
     * Além das seis sub-categorias avaliadas, ela recebe os dois casos de
     * indisponibilidade por classificação, uma categoria de giro rápido e um
     * produto encalhado.
     */
    private function criarConsulente(string $senha): Loja
    {
        $loja = $this->criarLoja(
            nome: self::NOME_CONSULENTE,
            email: self::EMAIL_CONSULENTE,
            nomeUsuario: 'Apresentação Maremu',
            senha: $senha,
            posicionamento: 'medio',
            faixa: 'de_10k_a_30k',
            canais: self::CANAIS_POR_PERFIL[1],
            // Custo fixo e volume conferidos pela lojista: R$ 15,00 por peça.
            ajustes: $this->premissas['consulente'],
        );

        // Giro rápido: vende muito mais que a faixa normal de camisetas.
        $this->criarCategoria($loja, self::CAMISETAS, 'Camisetas e tops', $this->premissas['vendas_90_dias_giro_rapido']);

        foreach ([self::CALCAS, self::VESTIDOS, self::SUTIAS, self::MEIAS] as $chave) {
            $this->criarCategoria($loja, $chave, $this->rotuloDaSubCategoria($chave));
        }

        // Casacos recebe o produto encalhado: estoque parado, última venda
        // muito antes do limite de 60 dias.
        $casacos = $this->criarCategoria($loja, self::CASACOS, 'Casacos e jaquetas');
        $this->criarProdutoEncalhado($loja, $casacos);

        // "Casacos › Outros" nunca é comparada: sub_categorias.comparavel = false.
        $this->criarCategoria($loja, self::NAO_COMPARAVEL, 'Acessórios de inverno');

        // Bazar sem sub-categoria: fica fora da camada 3 até ser classificado.
        $bazar = Categoria::create(['loja_id' => $loja->id, 'nome' => 'Bazar', 'sub_categoria_id' => null]);
        $this->criarProdutos($loja, $bazar, self::BAZAR, $this->premissas['preco_bazar']);

        return $loja;
    }

    private function criarLojasSemelhantes(string $senha): void
    {
        $contador = [];

        foreach (self::LOJAS_SEMELHANTES as $indice => [$posicionamento, $faixa, $chaves]) {
            $numero = str_pad((string) ($indice + 1), 2, '0', STR_PAD_LEFT);
            $contador[$posicionamento] = ($contador[$posicionamento] ?? 0) + 1;
            $rotulos = self::NOMES_DE_LOJA[$posicionamento];

            $loja = $this->criarLoja(
                nome: $rotulos[($contador[$posicionamento] - 1) % count($rotulos)].' Demo '.$numero,
                email: "demo-{$numero}@".self::DOMINIO,
                nomeUsuario: "Lojista Demo {$numero}",
                senha: $senha,
                posicionamento: $posicionamento,
                faixa: $faixa,
                canais: self::CANAIS_POR_PERFIL[$indice % count(self::CANAIS_POR_PERFIL)],
            );

            foreach ($chaves as $chave) {
                $this->criarCategoria($loja, $chave, $this->rotuloDaSubCategoria($chave));
            }
        }
    }

    /**
     * Cria usuário, loja e canais ativos. Os campos financeiros saem do próprio
     * OnboardingService, a partir da faixa — assim a loja de demonstração tem
     * exatamente os valores que o onboarding geraria para aquele porte, e o
     * faturamento cai de volta na faixa pedida.
     */
    private function criarLoja(
        string $nome,
        string $email,
        string $nomeUsuario,
        string $senha,
        string $posicionamento,
        string $faixa,
        array $canais,
        array $ajustes = [],
    ): Loja {
        $user = User::create([
            'name' => $nomeUsuario,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make($senha),
        ]);

        $inferido = app(OnboardingService::class)
            ->inferirDadosDaLoja($faixa, $posicionamento, 'simples_nacional', $canais);

        $loja = new Loja(array_merge($inferido['loja'], $ajustes, [
            'user_id' => $user->id,
            'nome' => $nome,
        ]));
        // Fora de $fillable: nenhuma rota marca uma loja como demonstração.
        $loja->is_demo = true;
        $loja->save();

        foreach ($inferido['canais'] as $canal) {
            $loja->canais()->create(array_merge($canal, ['ativo' => true]));
        }

        // Patamar de preço da loja: sorteado uma vez e válido para todo o
        // catálogo dela. É o que dá largura à faixa de mercado.
        [$min, $max] = $this->premissas['fator_preco_por_loja'];
        $this->fatorPreco[$loja->id] = $this->entre((int) round($min * 1000), (int) round($max * 1000)) / 1000;

        return $loja;
    }

    // ─────────────────────────────────────────────────────────────────────
    // CATEGORIAS, PRODUTOS E VENDAS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array{0: int, 1: int}|null  $vendas  unidades em 90 dias; null usa a premissa da sub-categoria
     */
    private function criarCategoria(Loja $loja, string $chave, string $nome, ?array $vendas = null): Categoria
    {
        $categoria = Categoria::create([
            'loja_id' => $loja->id,
            'nome' => $nome,
            'sub_categoria_id' => $this->subCategorias[$chave]->id,
        ]);

        $this->criarProdutos($loja, $categoria, $chave, $this->precoBase[$chave], $vendas);

        return $categoria;
    }

    /**
     * @param  array<string, float>  $precoBase  preço base por posicionamento
     * @param  array{0: int, 1: int}|null  $vendas
     */
    private function criarProdutos(Loja $loja, Categoria $categoria, string $chave, array $precoBase, ?array $vendas = null): void
    {
        // Preço de rede → patamar de loja pequena → patamar desta loja.
        $base = $precoBase[$loja->posicionamento]
            * $this->premissas['fator_loja_pequena']
            * $this->fatorPreco[$loja->id];
        $margemBase = $this->premissas['margem_bruta'][$loja->posicionamento];
        $faixaVendas = $vendas
            ?? $this->premissas['vendas_90_dias'][$chave]
            ?? $this->premissas['vendas_90_dias_padrao'];

        for ($i = 0; $i < $this->premissas['produtos_por_categoria']; $i++) {
            $preco = round($base * (1 + $this->desvio($this->premissas['variacao_preco'])), 2);
            $margem = $margemBase + $this->desvio($this->premissas['variacao_margem']);
            $custo = round($preco * (1 - $margem), 2);

            $produtoId = $this->registrarProduto($loja, $categoria, $this->nomeDeProduto($chave, $i), $preco, $custo);

            $this->registrarVendas($loja, $produtoId, $preco, $custo, $this->entre(...$faixaVendas));
        }
    }

    /**
     * Produto encalhado: estoque parado e última venda muito antes do limite
     * de 60 dias. É o gatilho da recomendação de liquidação.
     */
    private function criarProdutoEncalhado(Loja $loja, Categoria $categoria): void
    {
        $base = $this->precoBase[self::CASACOS][$loja->posicionamento]
            * $this->premissas['fator_loja_pequena']
            * $this->fatorPreco[$loja->id];
        $preco = round($base * 1.15, 2); // caro para o patamar da loja: parte do motivo de encalhar
        $custo = round($preco * (1 - $this->premissas['margem_bruta'][$loja->posicionamento]), 2);

        $produtoId = $this->registrarProduto($loja, $categoria, 'Casaco de inverno encalhado', $preco, $custo, estoquePorTamanho: 6);

        // Uma única venda, bem antes do corte de 60 dias sem venda.
        $this->registrarPedido($loja, $produtoId, $preco, $custo, 1, $this->premissas['dias_sem_venda_encalhado']);
    }

    /**
     * Sondas de piso: produtos da Loja Demo Apresentação cujo preço piso cai,
     * de propósito, acima do p75, dentro da faixa e abaixo do p25.
     *
     * O custo é resolvido de trás para frente, a partir do piso desejado, e o
     * piso efetivo é reconferido no PricingEngine — o custo é arredondado em
     * centavos, então o piso sai perto do alvo, não idêntico a ele.
     */
    private function criarSondasDePiso(Loja $consulente): void
    {
        $engine = app(PricingEngine::class);
        $mercado = app(Camada3MercadoService::class);

        // Mesma taxa que o PrecificacaoController usa: a maior entre os
        // canais ativos, para o piso cobrir o canal mais caro.
        $taxaCanal = (float) $consulente->canaisAtivos()->max('taxa_percentual');
        $aliquota = (float) $consulente->aliquota_efetiva;
        $custoFixo = (float) $consulente->custo_fixo_mensal;
        $volume = (int) $consulente->volume_vendas_esperado;

        foreach ($this->premissas['sondas_de_piso'] as $sonda) {
            $categoria = $consulente->categorias()
                ->where('sub_categoria_id', $this->subCategorias[$sonda['sub']]->id)
                ->sole();

            $faixa = $mercado->faixaDeMercado($consulente, $categoria);

            if (! $faixa['disponivel']) {
                $this->sondas[] = $sonda + [
                    'categoria' => $categoria->nome,
                    'resultado' => 'sem faixa disponível ('.$faixa['motivo'].')',
                ];

                continue;
            }

            $pisoAlvo = $faixa['preco'][$sonda['referencia']] * $sonda['fator'];
            $custoBase = $engine->custoBaseParaPiso($pisoAlvo, $aliquota, $taxaCanal, $custoFixo, $volume);

            if ($custoBase < $this->premissas['custo_minimo_plausivel']) {
                // Não força: um custo negativo ou de centavos seria dado falso.
                $this->sondas[] = $sonda + [
                    'categoria' => $categoria->nome,
                    'resultado' => sprintf(
                        'impossível — piso alvo de R$ %s exigiria custo de aquisição de R$ %s, '
                        .'abaixo do mínimo plausível: o custo fixo por peça (R$ %s) já supera o piso alvo',
                        $this->moeda($pisoAlvo),
                        $this->moeda($custoBase),
                        $this->moeda(round($custoFixo / $volume, 2)),
                    ),
                ];

                continue;
            }

            $calculo = $engine->calcularPreco(
                custoAquisicao: $custoBase,
                freteEntradaUnitario: 0.0,
                aliquotaEfetiva: $aliquota,
                taxaCanal: $taxaCanal,
                custoFixoMensal: $custoFixo,
                volumeVendasEsperado: $volume,
                margemLucroDesejada: (float) $consulente->margem_lucro_desejada,
            );

            $this->registrarProduto(
                $consulente,
                $categoria,
                $this->nomeDaSonda($sonda['zona']),
                // Sem preço de venda: é produto novo, aguardando precificação.
                // É esse o produto que a demonstração manda para a camada 1.
                preco: null,
                custo: $custoBase,
                precoPiso: $calculo['preco_piso'],
            );

            $this->sondas[] = $sonda + [
                'categoria' => $categoria->nome,
                'piso' => $calculo['preco_piso'],
                'custo_base' => $custoBase,
                'faixa' => $faixa['preco'],
                'resultado' => $this->zonaDoPiso($calculo['preco_piso'], $faixa['preco']),
            ];
        }
    }

    /** Em que zona da faixa o piso efetivo caiu. */
    private function zonaDoPiso(float $piso, array $preco): string
    {
        return match (true) {
            $piso > $preco['p75'] => 'acima_do_p75',
            $piso < $preco['p25'] => 'abaixo_do_p25',
            default => 'entre_p25_e_p75',
        };
    }

    /** Nome do tecido, para a sonda parecer produto e não fixture. */
    private function nomeDaSonda(string $zona): string
    {
        return match ($zona) {
            'acima_do_p75' => 'Linho importado',
            'abaixo_do_p25' => 'Malha simples',
            default => 'Algodão pima',
        };
    }

    private function registrarProduto(Loja $loja, Categoria $categoria, string $nome, ?float $preco, float $custo, ?int $estoquePorTamanho = null, ?float $precoPiso = null): string
    {
        $id = (string) Str::uuid();
        $cadastro = now()->subDays($this->entre(...$this->premissas['idade_cadastro_dias']));

        $this->filaProdutos[] = [
            'id' => $id,
            'loja_id' => $loja->id,
            'categoria_id' => $categoria->id,
            'nome' => $nome,
            'genero' => 'feminino',
            'sku' => strtoupper(Str::random(8)),
            'custo_aquisicao' => $custo,
            // Zero de propósito: a margem efetiva do produto fica igual à
            // margem sorteada, sem um frete embutido para explicar depois.
            'frete_entrada_unitario' => 0,
            'preco_piso' => $precoPiso,
            'preco_venda_atual' => $preco,
            'preco_origem' => $preco === null ? null : 'manual',
            'status' => 'ativo',
            'data_cadastro' => $cadastro,
            'created_at' => $cadastro,
            'updated_at' => $cadastro,
        ];

        foreach ($this->premissas['tamanhos'] as $tamanho) {
            $this->filaVariantes[] = [
                'id' => (string) Str::uuid(),
                'produto_id' => $id,
                'tamanho' => $tamanho,
                'quantidade_estoque' => $estoquePorTamanho ?? $this->entre(...$this->premissas['estoque_por_tamanho']),
                'estoque_minimo_alerta' => 3,
                'created_at' => $cadastro,
                'updated_at' => $cadastro,
            ];
        }

        return $id;
    }

    /**
     * Distribui as unidades do produto em pedidos espalhados pelo horizonte.
     */
    private function registrarVendas(Loja $loja, string $produtoId, float $preco, float $custo, int $unidades): void
    {
        $horizonte = (int) $this->premissas['horizonte_vendas_dias'];
        $restante = $unidades;

        while ($restante > 0) {
            $quantidade = min($restante, $this->entre(...$this->premissas['unidades_por_pedido']));
            $restante -= $quantidade;

            // 0 a horizonte−1: o job conta o horizonte a partir de hoje, então
            // uma venda de exatamente 90 dias atrás já ficaria fora.
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
        $canais = $this->premissas['canais'];

        $this->filaPedidos[] = [
            'id' => $pedidoId,
            'loja_id' => $loja->id,
            'canal_venda' => $canais[$this->itensVendidos % count($canais)],
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

    /**
     * Grava as filas em lotes, na ordem das dependências.
     */
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

    // ─────────────────────────────────────────────────────────────────────
    // SORTEIO E RÓTULOS
    // ─────────────────────────────────────────────────────────────────────

    private function entre(int $min, int $max): int
    {
        return $this->sorteio->getInt($min, $max);
    }

    /** Desvio uniforme em ±$amplitude, com resolução de 4 casas. */
    private function desvio(float $amplitude): float
    {
        return $this->sorteio->getInt(-10_000, 10_000) / 10_000 * $amplitude;
    }

    private function nomeDeProduto(string $chave, int $indice): string
    {
        $nomes = self::NOMES_DE_PRODUTO[$chave] ?? ['Peça avulsa'];

        return $nomes[$indice % count($nomes)].' '.self::CORES[$this->entre(0, count(self::CORES) - 1)];
    }

    /** Nome da sub-categoria na taxonomia, usado como nome da categoria da loja. */
    private function rotuloDaSubCategoria(string $chave): string
    {
        $sub = $this->subCategorias[$chave];

        return $sub->nome === 'Geral' ? $sub->categoriaPai->nome : $sub->nome;
    }

    // ─────────────────────────────────────────────────────────────────────
    // RESUMO
    // ─────────────────────────────────────────────────────────────────────

    private function resumir(Loja $consulente): void
    {
        if ($this->command === null) {
            return;
        }

        $onboarding = app(OnboardingService::class);
        $mercado = app(Camada3MercadoService::class);

        $lojas = Loja::where('is_demo', true)->get();

        $this->command->info("Lojas de demonstração: {$lojas->count()} (1 consulente + ".($lojas->count() - 1).' semelhantes)');
        $this->command->line('Acesso: '.self::EMAIL_CONSULENTE);
        $this->command->newLine();

        $distribuicao = $lojas
            ->groupBy([
                'posicionamento',
                fn (Loja $loja) => $onboarding->faixaDoFaturamento((float) $loja->faturamento_medio_mensal),
            ])
            ->map(fn ($faixas) => $faixas->map->count());

        $linhas = [];
        foreach (['popular', 'medio', 'premium'] as $posicionamento) {
            foreach (($distribuicao[$posicionamento] ?? collect()) as $faixa => $total) {
                $linhas[] = [$posicionamento, $faixa, $total];
            }
        }
        $this->command->line('Distribuição (a consulente entra na contagem de medio · de_10k_a_30k):');
        $this->command->table(['posicionamento', 'faixa de faturamento', 'lojas'], $linhas);

        $this->command->info('Camada 3 a partir da '.self::NOME_CONSULENTE.':');

        $linhas = [];
        foreach ($consulente->categorias()->with('subCategoria.categoriaPai')->orderBy('nome')->get() as $categoria) {
            $faixa = $mercado->faixaDeMercado($consulente, $categoria);
            $sub = $categoria->subCategoria;

            $linhas[] = [
                $categoria->nome,
                $sub === null ? '— sem classificação —' : $sub->categoriaPai->nome.' › '.$sub->nome,
                $faixa['disponivel']
                    ? $faixa['n_lojas'].' lojas · '.implode(' + ', $faixa['filtros_aplicados'])
                    : 'indisponível',
                $faixa['disponivel']
                    ? 'R$ '.$this->moeda($faixa['preco']['p25']).' / '.$this->moeda($faixa['preco']['mediana']).' / '.$this->moeda($faixa['preco']['p75'])
                    : $faixa['motivo'],
            ];
        }
        $this->command->table(['categoria', 'sub-categoria', 'amostra', 'preço p25 / mediana / p75'], $linhas);

        $this->command->info('Sondas de piso (custo resolvido a partir do piso desejado):');

        $linhas = [];
        foreach ($this->sondas as $sonda) {
            $linhas[] = [
                $sonda['categoria'],
                $sonda['zona'].' ('.$sonda['referencia'].' × '.$sonda['fator'].')',
                isset($sonda['custo_base']) ? 'R$ '.$this->moeda($sonda['custo_base']) : '—',
                isset($sonda['piso']) ? 'R$ '.$this->moeda($sonda['piso']) : '—',
                $sonda['resultado'],
            ];
        }
        $this->command->table(['categoria', 'zona pedida', 'custo resolvido', 'piso efetivo', 'zona alcançada'], $linhas);
    }

    private function moeda(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
