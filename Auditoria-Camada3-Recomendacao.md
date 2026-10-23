# Auditoria — Camada 3 e Recomendação de Cenário V1

Data: 07/10/2026. Escopo: código do workspace em `maremu-api` (Laravel) e `maremu_frontend` (Next.js), incluindo alterações locais ainda sem commit. **Somente leitura**, exceto este relatório; nenhum comando de aplicação, teste, migration, seed, deploy ou escrita no banco foi executado. Não houve chamada à IA nem consulta ao Azure. Schema abaixo é o declarado pelas migrations, não confirmação do schema instalado ou dos dados reais.

**Limitação documental:** os dois planos mencionados não foram disponibilizados no contexto recebido. A busca por nomes de plano/Camada/Recomendação e arquivos Markdown, PDF e DOCX nos repositórios não os encontrou. A comparação literal com os planos permanece pendente; a seção Divergências registra premissas não sustentadas pelo código, sem atribuí-las aos anexos.

Referências `app/...`, `database/...`, `routes/...`, `config/...` e `tests/...` são relativas a `maremu-api`; referências iniciadas por `maremu_frontend/` são relativas ao workspace `/home/marcos/maremu`. Cada referência inclui linhas do código auditado. **Visto** indica implementação declarada; **Inferência** indica consequência ou proposta técnica, sem decisão de produto.

## A. Categorias e produtos

### 1. Modelagem atual

**Visto:** existe a tabela `categorias`: `id UUID PK`, `nome string`, `loja_id UUID nullable FK lojas.id ON DELETE CASCADE`, `created_at` e `updated_at`. Não há enum de nomes, taxonomia global ou unicidade de nome no schema. O nome é texto livre na categoria, não no produto. `produtos.categoria_id` é UUID obrigatório com FK para `categorias.id`; `produtos.loja_id` é UUID obrigatório com FK para lojas. Fontes: `database/migrations/0001_01_01_000004_create_categorias_table.php:14-20`; `database/migrations/0001_01_01_000006_create_produtos_table.php:14-31`.

**Visto:** `Categoria::loja()` belongsTo Loja e `Categoria::produtos()` hasMany Produto; `Produto::categoria()` belongsTo Categoria; `Loja::categorias()` e `Loja::produtos()` hasMany. Fontes: `app/Models/Categoria.php:25-33`; `app/Models/Produto.php:46-59`; `app/Models/Loja.php:54-62`.

**Visto:** fluxo público cria categoria com a loja autenticada, lista apenas suas categorias e valida nome único nessa loja. Cadastro/edição de produto exige categoria pertencente à loja. O schema permite `loja_id=null` e os testes efetivamente criam categorias assim; isso não equivale a uma taxonomia global implementada. Fontes: `app/Http/Controllers/CategoriaController.php:11-34`; `app/Http/Requests/StoreCategoriaRequest.php:17-26`; `app/Http/Requests/StoreProdutoRequest.php:17-25`; `tests/Unit/PrecificacaoControllerTest.php:43-45`.

### 2. Encaixe de sub_categoria_id e impactos

**Não encontrado:** `sub_categoria_id`, model/tabela de subcategorias ou cadastro de taxonomia; procurado em `app`, `database`, `routes` e `maremu_frontend/src`. Os campos atuais estão em `app/Models/Categoria.php:20-23` e `app/Models/Produto.php:21-35`.

**Inferência técnica condicional:** se cada categoria da loja representar exatamente uma subcategoria canônica, colocar a FK em `categorias` tende a ter menor impacto: todos os produtos já apontam para uma categoria e o mapeamento seria feito uma vez por categoria. Se uma categoria local puder conter produtos de várias subcategorias canônicas, a FK no produto preserva essa granularidade. O código não permite decidir essa cardinalidade de produto.

| Opção | Pontos que precisariam mudar (não alterados nesta auditoria) |
| --- | --- |
| FK em categorias | Migration e model Categoria; request de criação, controller de criação/listagem; UX de seleção/mapeamento no modal de categoria dentro de ProductForm; processo de mapear categorias existentes. Referências: `app/Models/Categoria.php:20-33`; `app/Http/Requests/StoreCategoriaRequest.php:19-27`; `app/Http/Controllers/CategoriaController.php:22-34`; `maremu_frontend/src/app/(dashboard)/produtos/ProductForm.tsx:11`, `136-150`, `546-571`. Não há endpoint atual de editar categoria em `routes/api.php:26-28`. |
| FK em produtos | Migration/model Produto; StoreProdutoRequest e UpdateProdutoRequest (herda o primeiro); store/update do controller; tipo, estado, carga, payload e seletor do ProductForm usados no cadastro e edição. Referências: `app/Models/Produto.php:21-35`; `app/Http/Requests/StoreProdutoRequest.php:19-35`; `app/Http/Requests/UpdateProdutoRequest.php:5-7`; `app/Http/Controllers/ProdutoController.php:57-67`, `112-120`; `maremu_frontend/src/app/(dashboard)/produtos/ProductForm.tsx:13-23`, `82-123`. |

**Inferência:** nas duas opções, leitura da classificação no agregador e payload teria de ser acrescentada (`app/Jobs/AgregarMetricasCategoria.php:24-36`; `app/Services/PrecificacaoPayloadService.php:98-102`). Listagem/filtro, PDV e relatórios só precisariam de mudanças visuais se a nova classificação for exposta: `maremu_frontend/src/app/(dashboard)/produtos/page.tsx:96-97`, `341`; `app/Http/Controllers/SaidaController.php:25-26`, `57`; `app/Http/Controllers/RelatorioController.php:32-33`. A validação de categoria por loja deve continuar existindo.

### 3. Quantidade nos seeders e factories

**Visto:** **0 categorias, 0 produtos e 0 lojas** definidos nos seeders/factories atuais. Existe apenas `DatabaseSeeder`, que cria um usuário fixo `Test User / test@example.com`, e apenas `UserFactory`, que gera nome, email, verificação, senha e remember_token. Fontes: `database/seeders/DatabaseSeeder.php:16-23`; `database/factories/UserFactory.php:25-33`. Inventário de `database/seeders` e `database/factories` não encontrou outros arquivos. Isso é contagem de definições, não contagem do banco.

**Visto, fora dessa contagem:** o comando experimental de variância cria/reutiliza uma loja laboratório, a categoria `calças` e o produto `calça cargo`; não é seeder/factory. Fonte: `app/Console/Commands/TestarVarianciaPrecificacao.php:57-99`.

## B. Métricas e job

### 4. Tabela, schema e estratégia de gravação

**Visto:** grava em `metricas_categoria_loja`. Schema completo declarado:

| Campo | Tipo / restrição |
| --- | --- |
| id | UUID, PK |
| loja_id | UUID obrigatório, FK lojas.id, cascadeOnDelete |
| categoria_id | UUID obrigatório, FK categorias.id |
| periodo_referencia | date obrigatório |
| giro_medio_dias | decimal(8,2), nullable |
| margem_realizada_media | decimal(5,2), nullable |
| margem_planejada_media | decimal(5,2), nullable |
| qtd_vendas_periodo | integer, default 0 |
| volume_minimo_atingido | boolean, default false |
| candidatos_liquidacao | JSON, nullable |
| desatualizada | boolean, default false (migration adicional) |
| data_calculo | timestamp, default current |
| created_at / updated_at | timestamps |

Chave única adicional: `(loja_id, categoria_id, periodo_referencia)`, nome `uk_metricas_loja_cat_periodo`. Fontes: `database/migrations/0001_01_01_000011_create_metricas_categoria_loja_table.php:14-29`; `database/migrations/2026_08_03_000001_add_desatualizada_to_metricas_categoria_loja_table.php:14-16`.

**Visto:** a implementação verifica existência apenas por loja+categoria. Se existe, atualiza **todos** os registros dessa combinação, inclusive `periodo_referencia=hoje`; se não, insere UUID novo. Não insere uma série diária automaticamente. Fontes: `app/Jobs/AgregarMetricasCategoria.php:40-69`.

**Inferência:** com múltiplos períodos já existentes, atualizar todos para a mesma data pode violar a unique. Categorias sem vendas na janela não entram no loop, portanto registros antigos não são zerados nem desativados pelo job. A janela tem limite inferior de 90 dias e não tem limite superior explícito; vendas futuras também satisfariam a consulta. Fontes: `app/Jobs/AgregarMetricasCategoria.php:19-37`, `40-70`.

### 5. Fórmula EXATA de giro_medio_dias

**Não encontrado:** cálculo real de `giro_medio_dias` por unidade ou por produto, data inicial de contagem ou tratamento de produtos sem venda. Procurado em todo `app`, `database`, `routes`, `tests` e frontend por `giro_medio_dias`/giro. O job **não escreve esse campo**. Trecho exato que efetivamente escreve as métricas (`app/Jobs/AgregarMetricasCategoria.php:46-54`):

```php
$dadosAtualizacao = [
    'qtd_vendas_periodo' => $linha->qtd_vendas_periodo,
    'margem_realizada_media' => round((float) $linha->margem_realizada_media, 4),
    'volume_minimo_atingido' => $linha->qtd_vendas_periodo >= 1, // Limite para ativar a Camada 4
    'periodo_referencia' => now()->toDateString(),
    'data_calculo' => now(),
    'desatualizada' => false, // Reseta a flag para a IA
    'updated_at' => now(),
];
```

**Visto:** o agregador parte de itens vendidos, não do catálogo completo. Usa `COUNT(*)` (linhas, não SUM quantidade nem COUNT DISTINCT produto) e `AVG((preco_unitario_vendido-custo_unitario_no_momento)/NULLIF(preco_unitario_vendido,0))`. Não pondera quantidades nem desconta `desconto_aplicado`. `preco_medio_vendido` é selecionado mas não persistido. Fonte: `app/Jobs/AgregarMetricasCategoria.php:22-36`, `46-54`.

**Inferência:** registro novo tende a ter giro, margem planejada e candidatos nulos pelos defaults nullable; valores antigos nesses campos são preservados quando o job atualiza. Produtos sem venda não participam dessa agregação. A data `now()->subDays(90)` é início da janela de vendas, **não** início de um cálculo de giro.

**Visto:** valores de giro aparecem como fixtures artificiais no comando de variância e nos testes, não como cálculo operacional: `app/Console/Commands/TestarVarianciaPrecificacao.php:254-273`, `283-295`; `tests/Unit/PrecificacaoPayloadServiceTest.php:175-210`.

### 6. Razões e consumidores

**Visto:** `razao_margem = round(margem_realizada_media / margem_planejada_media, 4)`, retornando null se qualquer margem falta ou planejada <= 0. `giro_esperado_dias = 30 / (volume_vendas_esperado / 30)` (equivale a 900/volume); `razao_giro = round(giro_medio_dias / giro_esperado_dias, 4)`, null se giro falta ou volume <= 0. Fontes: `app/Services/PrecificacaoPayloadService.php:195-203`, `219-225`. Giro esperado não usa estoque ou quantidade de produtos; não é a fórmula do giro real.

**Visto — inventário dos consumidores encontrados:**

- `PrecificacaoPayloadService::montarCamada4()` lê métricas, produz razões e aviso: `app/Services/PrecificacaoPayloadService.php:124-176`.
- `PrecificacaoController::sugerir()` envia o payload à implementação de `PrecificacaoIaInterface` e grava log: `app/Http/Controllers/PrecificacaoController.php:47-59`.
- Prompt compartilhado orienta a IA pela margem (<0,70; 0,70–0,90; >=0,90) e giro (>1,5; 0,8–1,5; <0,8), priorizando margem em divergência: `app/Services/Concerns/PrecificacaoIaPrompt.php:37-53`.
- Provedores Anthropic, Gemini e OpenAI recebem esse contrato; binding em `app/Providers/AppServiceProvider.php:25-30`; transporte do JSON, por exemplo, `app/Services/AnthropicService.php:55-80`.
- Comando experimental gera fixtures, monta payload e imprime razões: `app/Console/Commands/TestarVarianciaPrecificacao.php:102-115`, `240-295`.
- Model declara campos/casts/relacionamentos: `app/Models/MetricasCategoriaLoja.php:13-46`; testes usam fixtures e verificam payload: `tests/Unit/PrecificacaoPayloadServiceTest.php:148-210`.
- `SaidaController` é escritor da flag desatualizada, não consumidor das razões: `app/Http/Controllers/SaidaController.php:342-350`.

**Não encontrado:** `MargemLiquidacaoService`, consumo dessas razões no PricingEngine, dashboard, relatórios ou frontend. Busca por nome da classe, tabela, model e nomes das métricas em `app`, `routes` e `maremu_frontend/src`. Relatório calcula outras medidas: markup sobre custo e lucro realizado, sem ler essa tabela (`app/Http/Controllers/RelatorioController.php:23-43`, `184-241`). **Inferência:** não se deve confundir markup visual do relatório com a margem sobre preço do agregador. Há também diferença de precisão: job arredonda a 4 casas, coluna/cast retêm 2 (`app/Jobs/AgregarMetricasCategoria.php:48`; migration de métricas:20; `app/Models/MetricasCategoriaLoja.php:31-32`).

### 7. Disparo, fila e Azure

**Visto:** classe implementa `ShouldQueue`, mas o caminho registrado é comando manual `php artisan metricas:agregar` com `AgregarMetricasCategoria::dispatchSync()`. Não há Schedule::job ativo em `routes/console.php`. Fontes: `app/Jobs/AgregarMetricasCategoria.php:12-14`; `routes/console.php:11-16`.

**Visto:** config da fila usa `env('QUEUE_CONNECTION','database')`; `.env.example:38` define database; `deploy-azure.sh:69-74` define **sync** para app/job; `render.yaml:52-58` também define sync; `phpunit.xml:31` usa sync. Config local efetiva e configuração implantada não foram consultadas. Um worker de desenvolvimento aparece em `composer.json:46` (`queue:listen`), não é configuração de worker Azure.

**Visto:** `deploy-azure.sh` tem `cmd_job()` que cria Container Apps Job agendado, default cron `0 6 * * *` (06h UTC / 03h Brasília), comando `php artisan metricas:agregar`. Deploy atualiza imagem do job **se ele existir**. Fontes: `deploy-azure.sh:29-34`, `182-204`. O job substitui entrypoint, segundo script:186-193. Docker inicia Apache; entrypoint faz cache/migrations e exec do comando, sem worker/schedule: `Dockerfile:46-52`; `docker/entrypoint.sh:17-26`. Esses scripts foram apenas lidos.

**Não encontrado:** configuração de `schedule:run`, cron interno, supervisor ou workflow Azure em outros arquivos do repositório; inventário incluiu arquivos ocultos e busca de scheduler/worker. **Inferência:** o caminho pretendido é cron externo Azure executando job síncrono; não exige worker. Existência, execução bem-sucedida e última execução do recurso Azure **não confirmadas**. Comentário antigo sobre Schedule::job em `render.yaml:53` não é registro executável atual.

## C. Vendas e estoque

### 8. Venda datada por produto e schema

**Visto:** venda é `pedidos` + `itens_pedido`, não tabela `vendas`. Schema completo:

- `pedidos`: `id UUID PK`; `loja_id UUID FK lojas.id cascade`; `canal_venda enum(loja_fisica,instagram_whatsapp,marketplace,outro)`; `valor_total decimal(10,2)`; `forma_pagamento string nullable`; `data_venda timestamp current`; `created_at/updated_at`. Fonte: `database/migrations/0001_01_01_000009_create_pedidos_table.php:14-25`.
- `itens_pedido`: `id UUID PK`; `pedido_id UUID FK pedidos.id cascade`; `produto_id UUID FK produtos.id`; `variante_id UUID nullable FK variantes_produto.id nullOnDelete`; `quantidade integer`; `preco_unitario_vendido decimal(10,2)`; `custo_unitario_no_momento decimal(10,2)`; `desconto_aplicado decimal(10,2) default 0`; `created_at/updated_at`. Fonte: `database/migrations/0001_01_01_000010_create_itens_pedido_table.php:14-27`.

**Visto:** PDV grava data_venda=agora e itens com produto/variante/quantidade/custo/preço/desconto na mesma transação. Fontes: `app/Http/Controllers/SaidaController.php:183`, `280-329`. **Inferência:** data da venda por produto deriva do join item.pedido_id → pedido.id. Não há snapshot do cenário ou categoria no item; o job usa a categoria **atual** do produto (`app/Jobs/AgregarMetricasCategoria.php:24-28`).

### 9. Estoque atual e movimentações

**Visto:** estoque é `variantes_produto.quantidade_estoque`, integer default 0; variante tem `id UUID PK`, `produto_id FK cascade`, `tamanho string`, `estoque_minimo_alerta integer default 3`, timestamps. Total por produto é soma das variantes. Fontes: `database/migrations/0001_01_01_000007_create_variantes_produto_table.php:14-22`; `app/Http/Controllers/SaidaController.php:43-60`.

**Visto:** há schema de `entradas`: `id UUID PK`, `loja_id FK cascade`, `produto_id FK cascade`, `quantidade integer`, `custo_unitario decimal(10,2)`, `frete_entrada decimal(10,2) default 0`, `fornecedor string nullable`, `data_entrada timestamp current`, timestamps. Fonte: `database/migrations/0001_01_01_000008_create_entradas_table.php:14-26`.

**Não encontrado:** ledger unificado de movimentações, model/controller/rota ou gravação operacional de `entradas`. Busca por `entradas`, movimentações e inventário de models/controllers/routes. Cadastro grava estoque diretamente nas variantes (`app/Http/Controllers/ProdutoController.php:69-75`); venda reduz estoque diretamente (`app/Http/Controllers/SaidaController.php:324-329`).

### 10. Estoque em data passada

**Visto:** só há saldo atual garantido pelo fluxo. Cadastro não grava entrada; edição de produto apaga todas as variantes e as recria com o saldo informado; não registra delta/snapshot. Fontes: `app/Http/Controllers/ProdutoController.php:69-75`, `122-130`.

**Inferência:** não é possível reconstruir estoque histórico com confiabilidade no fluxo atual. Reverter vendas a partir do saldo só funcionaria sob a hipótese não comprovada de ausência de reposições, correções e exclusões. `entradas` ter schema não garante que esteja alimentada; tampouco contém variante. Excluir/recriar variante faz a FK histórica de item tornar-se null (`database/migrations/0001_01_01_000010_create_itens_pedido_table.php:25`). **Não encontrado:** snapshot de fechamento de janela ou trilha de ajustes em `database/migrations`, `app` e `routes`.

### 11. Datas e quantidade por produto

| Dado | Visto / derivação possível / limite |
| --- | --- |
| Cadastro | Campo direto `produtos.data_cadastro`, default current; cast datetime. Não comprova entrada física. Fontes: migration produtos:28; `app/Models/Produto.php:42`. |
| Entrada física | `entradas.data_entrada` existe; MIN/MAX por produto seriam deriváveis **se** a tabela for alimentada. Gravador no app não encontrado. Fonte: migration entradas:17-22. Cadastro de variante.created_at também é data de registro, perde identidade na edição (ProdutoController:122-130). |
| Última venda | Derivável como MAX(pedidos.data_venda) no join com itens filtrados por produto; sem venda → null. Não há coluna direta no produto (migration produtos:14-31; model Produto:21-44). Relação item/produto/pedido declarada nas migrations de pedidos:22 e itens:16-24. |
| Quantidade vendida | Derivável por SUM(itens_pedido.quantidade). Número de linhas é COUNT(*); transações por produto é COUNT(DISTINCT pedido_id). Não são a mesma medida. Schema itens:16-19; relatório já soma quantidade em `app/Http/Controllers/RelatorioController.php:203-208`. |

**Não encontrado:** tratamento de devoluções/estornos ou rastreamento por lote para atribuir cada unidade vendida a uma entrada; busca em migrations, controllers e routes. Logo derivação é das vendas registradas, sem garantia de vendas líquidas de devolução.

## D. Lojas

### 12. Onboarding, faixas e demo

**Visto:** `lojas.posicionamento enum(popular,medio,premium)` e `faturamento_medio_mensal decimal(12,2)` são persistidos. Fontes: migration lojas:17-25; `app/Http/Controllers/LojaController.php:66-78`; `app/Http/Controllers/OnboardingIaController.php:333-335`.

**Visto:** faixa de faturamento existe como entrada do onboarding tradicional (`ate_10k`, `de_10k_a_30k`, `de_30k_a_80k`, `acima_80k`), metadado do retorno e derivação auxiliar no onboarding IA. Fontes: `app/Http/Requests/OnboardingInferirRequest.php:22`; `app/Services/OnboardingService.php:163`, `175-190`; `app/Http/Controllers/OnboardingIaController.php:385-391`.

**Não encontrado:** coluna persistida de faixa em lojas ou flag teste/demo em migrations/model Loja e fluxos de gravação (migration lojas:11-34; `app/Models/Loja.php:17-37`). **Visto:** existe criação de loja laboratório pelo comando de variância, sem flag (`app/Console/Commands/TestarVarianciaPrecificacao.php:57-78`). **Inferência:** agrupar lojas por porte exigiria derivar uma faixa ou acrescentar classificação; excluir dados de teste por nome/email seria heurística, não contrato atual.

### 13. Isolamento e consulta entre lojas

**Visto:** isolamento é manual: relacionamentos da loja autenticada, filtros explícitos e checagem de propriedade; auth Sanctum + middleware verificam autenticação/existência de loja. Middleware não aplica filtro de tenant à query. Fontes: `routes/api.php:26-41`; `app/Http/Middleware/EnsureLojaExists.php:22-33`; `app/Http/Controllers/ProdutoController.php:21-24`, `150-154`; `app/Http/Controllers/PrecificacaoController.php:34-37`, `150-154`; `app/Http/Requests/StoreProdutoRequest.php:21-24`.

**Não encontrado:** global scope de loja, ScopedBy ou registro de scope em `app/Models`, providers e bootstrap. Modelos usam Eloquent diretamente (`app/Models/Produto.php:13-59`; `app/Models/Loja.php:13-62`; `app/Providers/AppServiceProvider.php:49-52`). **Visto:** o agregador já consulta todas as lojas via DB::table (`app/Jobs/AgregarMetricasCategoria.php:22-36`).

**Inferência técnica:** consulta entre lojas não seria bloqueada automaticamente; não há scope a remover. Pode ficar em serviço/job interno específico, com filtros de elegibilidade explícitos e retorno agregado para a loja solicitante, preservando os filtros/validações atuais das rotas. Não usar alteração global nos models ou tornar endpoints de catálogo acessíveis entre lojas. Critérios de elegibilidade e privacidade ficam para o grupo.

### 14. Seeders de loja

**Não encontrado:** LojaSeeder ou qualquer seeder criando lojas; inventário completo de `database/seeders` só contém `DatabaseSeeder.php`, que cria usuário (linhas 16-23). **Visto:** comando de variância, que não é seeder, cria loja médio/simples_nacional, faturamento 20.000, fixo 5.000, margem 0,30, alíquota 0,109, volume 120, categoria calças/produto calça cargo. Fonte: `app/Console/Commands/TestarVarianciaPrecificacao.php:57-99`. Não executado.

## E. Fluxo de precificação

### 15. Logs e cenário escolhido/em uso

**Visto:** schema `logs_sugestao_ia`: `id UUID PK`, `produto_id UUID FK produtos.id cascade`, `payload_enviado JSON obrigatório`, `provedor_ia string nullable` (migration posterior), `cenarios_retornados JSON nullable`, `cenario_escolhido enum(cenario_1,cenario_2,cenario_3,manual) nullable`, `preco_final_escolhido decimal(10,2) nullable`, timestamps. Fontes: `database/migrations/0001_01_01_000012_create_logs_sugestao_ia_table.php:14-25`; `database/migrations/2026_08_27_000000_add_provedor_ia_to_logs_sugestao_ia_table.php:14-18`.

**Visto:** confirmar grava escolha e preço no log, e preço atual/origem no produto. Produto guarda `preco_origem enum(ia_cenario_1,ia_cenario_2,ia_cenario_3,manual) nullable`. Fontes: `app/Http/Controllers/PrecificacaoController.php:167-181`; migration produtos:23-26. **Não encontrado:** FK de produto para log ativo, campo de cenário recomendado ou versão da recomendação, procurados em migrations, models, controller e frontend. **Inferência:** origem identifica cenário informado, mas não vincula inequivocamente à rodada ativa, e pode ficar incoerente após PUT comum (questão 16).

### 16. Preço manual e registro

**Visto:** modal aceita preço manual e muda seleção para manual; com logId confirma via endpoint e grava ambos os registros. Fontes: `maremu_frontend/src/app/(dashboard)/produtos/PricingModal.tsx:140-151`; `maremu_frontend/src/app/(dashboard)/produtos/ProductForm.tsx:184-186`, `210-224`; `app/Http/Controllers/PrecificacaoController.php:144-181`. Confirmação valida manual >=0,01 e não exige equivalência com os cenários; para IA valida equivalência com tolerância de 0,01 (linhas 147,156-165). Não há validação de preço manual contra piso nesse método.

**Visto:** cadastro/edição também aceitam `preco_venda_atual` >=0 diretamente. Store/update não gravam `preco_origem` nem log. Sem logId no frontend, fallback usa PUT direto. Fontes: `app/Http/Requests/StoreProdutoRequest.php:27`; `app/Http/Controllers/ProdutoController.php:57-67`, `112-120`; `maremu_frontend/src/app/(dashboard)/produtos/ProductForm.tsx:220-224`.

**Inferência:** preço manual direto pode ficar sem origem/log (cadastro) ou manter origem anterior apesar de mudar preço (edição). Se IA falha depois da criação do log, o backend mantém log (`PrecificacaoController:52-68`), mas frontend não recebe seu id numa resposta de erro e usa fallback; portanto “sem logId no frontend” não prova ausência de log no banco.

### 17. Reprecificação de produto existente

**Visto:** API `POST /api/precificacao/sugerir/{produto}` aceita qualquer produto da loja com piso não nulo; não exige recém-criado. Cada chamada cria log novo, chama IA para margens e calcula preços via PricingEngine; confirmar atualiza o produto. Fontes: `routes/api.php:35-36`; `app/Http/Controllers/PrecificacaoController.php:30-81`, `92-115`.

**Visto:** frontend `/produtos/[id]` renderiza ProductForm com productId; submit faz PUT e redireciona. Só branch `!productId` abre modal/chama IA. Botão “Recalcular com a IA” do modal usa createdProductId da sessão de cadastro. Fontes: `maremu_frontend/src/app/(dashboard)/produtos/[id]/page.tsx:1-10`; `maremu_frontend/src/app/(dashboard)/produtos/ProductForm.tsx:262-295`, `652-655`; `maremu_frontend/src/app/(dashboard)/produtos/PricingModal.tsx:163-169`.

**Conclusão vista:** existe suporte de API para nova rodada em produto existente; fluxo dedicado de reprecificação pelo frontend **não encontrado** em `maremu_frontend/src/app/(dashboard)/produtos`. PUT recalcula piso, mas não chama IA (`app/Http/Controllers/ProdutoController.php:99-120`).

### 18. Payload atual da IA

**Visto:** envelope tem `camada_1`, `camada_2`, `camada_4`, `meta`; sem `camada_3`. Fontes: `app/Services/PrecificacaoPayloadService.php:42-52`.

- Camada 1: preco_piso, custo_base e custo_origem {aquisicao,frete} (`PrecificacaoPayloadService:61-70`).
- Camada 2 loja: posicionamento, regime_tributario, faturamento_medio_mensal, custo_fixo_mensal, custo_fixo_origem, margem_lucro_desejada, aliquota_efetiva, aliquota_origem, volume_vendas_esperado. Canais ativos: canal/taxa_percentual/taxa_origem. Produto: nome/categoria (nome)/status e genero/sku se não nulos (`PrecificacaoPayloadService:79-111`).
- Camada 4: periodo_referencia, giro_medio_dias, margem_realizada_media, margem_planejada_media, qtd_vendas_periodo, data_calculo, razao_margem, razao_giro; candidatos_liquidacao se não vazio; aviso se desatualizada. Campos nulos internos são removidos (`PrecificacaoPayloadService:136-176`). Seleciona último periodo_referencia com volume_minimo_atingido=true na loja/categoria (`124-130`).
- Meta: produto_id, loja_id, categoria_id, gerado_em (`PrecificacaoPayloadService:46-51`).

**Visto:** sem métrica elegível, `montarCamada4()` retorna null, mas `montar()` inclui a chave `camada_4 => null`; comentário “omitido do payload” é impreciso. Fonte: `PrecificacaoPayloadService:45`, `132-134`. Anthropic serializa o array inteiro em JSON, junto de prompt/schema (`app/Services/AnthropicService.php:64-79`). **Não encontrado:** estoque atual, última venda, idade em estoque, preço atual, faixa de mercado ou recomendação individual nesse payload. Campos enumerados acima são a estrutura efetiva.

## F. Frontend

### 19. Arquivos das telas

| Tela | Arquivo / linhas |
| --- | --- |
| Cadastro | `maremu_frontend/src/app/(dashboard)/produtos/novo/page.tsx:1-3`, delega ao `ProductForm.tsx:46` |
| Listagem | `maremu_frontend/src/app/(dashboard)/produtos/page.tsx:96-97`, `125-137`, `308-341` |
| Detalhe por id | `maremu_frontend/src/app/(dashboard)/produtos/[id]/page.tsx:3-9`: é formulário de edição, não página independente de detalhe somente leitura |
| Formulário compartilhado | `maremu_frontend/src/app/(dashboard)/produtos/ProductForm.tsx:46-134`, `253-295` |
| Modal | `maremu_frontend/src/app/(dashboard)/produtos/PricingModal.tsx:28-175`; integração `ProductForm.tsx:641-659` |

### 20. Claude Design e animações

**Visto:** modal implementado, com comentário explícito de reimplementação do protótipo Claude Design (`PricingModal.tsx:23-26`), portal, backdrop, estados loading/erro, cards de cenários, entrada manual, salvar/recalcular. Classes mm-fade/mm-rise-in/mm-hover/mm-dot e stagger são usadas (`61-64`, `82-108`, `140-168`) e têm regras de movimento em `maremu_frontend/src/app/globals.css:9-68`.

**Visto — lacuna:** modal usa classe `mm-pop-in-centered` (`PricingModal.tsx:64`); CSS define **keyframes** com esse nome (`globals.css:29`), mas não a regra `.mm-pop-in-centered { animation: ... }` no bloco de classes (`38-48`). Busca completa em src não encontrou declaração da classe. **Inferência:** a animação de entrada central específica não fica vinculada por essa classe; outras animações estão definidas. Media reduced-motion também não lista a classe central nem mm-fade (`globals.css:64-68`).

**Não encontrado:** arquivo original do protótipo ou pasta `maremu_frontend/design`; CSS referencia `design/Padrões de Movimento.dc.html` em `globals.css:11`, mas esse caminho não existe no checkout. Portanto há implementação atribuída ao Claude Design no código, sem evidência para atestar paridade completa com o protótipo. Nenhuma validação visual em navegador foi executada.

## G. Testes

### 21. Montagem de dados e factories multi-loja

**Visto:** testes Feature de onboarding usam RefreshDatabase, User::factory, mocks de IA e requests para criar loja; não factories de loja/produto/venda. Fontes: `tests/Feature/OnboardingIaTest.php:14-21`, `145-177`; `tests/Feature/OnboardingPendenciasTest.php:18-25`. Feature Example não monta domínio (`tests/Feature/ExampleTest.php:5`).

**Visto:** testes de controller/payload/relatório estão em `tests/Unit`, mas vários usam banco/RefreshDatabase e `Loja::create`, `Categoria::create`, `Produto::create`, `VarianteProduto::create`. Exemplos: `tests/Unit/PrecificacaoControllerTest.php:19-57`, `75-109`; `tests/Unit/PrecificacaoPayloadServiceTest.php:22`, `31-53`; `tests/Unit/RelatorioControllerTest.php:19`, `29-40`, `75-92`. Há teste com duas lojas e produtos, construídos manualmente, para isolamento. Alguns fixtures criam categoria sem loja, diferente da validação da API.

**Não encontrado:** factories Loja/Produto/Categoria/Variante/Pedido/ItemPedido/Entrada; montagem de vendas por factories ou inserts em `tests`, testes específicos do agregador e de ledger histórico. Busca no inventário `database/factories`, e em `tests` por factories, pedidos, itens_pedido e AgregarMetricasCategoria. Única factory: `database/factories/UserFactory.php:13-33`.

**Inferência:** é viável montar várias lojas e vendas manualmente com Eloquent/DB e os schemas existentes, mas não há factories suficientes prontas para fazê-lo por relações de factory. Fixtures reais de vendas multi-loja, datas e quantidades precisam ser acrescentadas antes de validar as novas fases. Nenhum teste foi executado, pois RefreshDatabase/montagem de dados escreve no banco.

## Divergências

**Comparação com os planos anexados: pendente**, por ausência dos documentos. As divergências abaixo são entre possíveis premissas de implementação e o código; não são citações nem afirmações sobre o conteúdo dos planos.

| Premissa a revisar na spec | Evidência atual |
| --- | --- |
| Taxonomia comparável entre lojas/subcategorias já pronta | Categoria local com nome livre; FK canônica não encontrada. Migration categorias:14-20; StoreProdutoRequest:21-24. |
| Job já calcula giro real, margem planejada e candidatos | Não os calcula; somente margem realizada/count/flags/datas. AgregarMetricasCategoria:46-54. |
| qtd_vendas_periodo equivale a unidades vendidas | COUNT(*) de itens, sem ponderação de quantidade. AgregarMetricasCategoria:29-34. |
| Histórico diário de métricas mantido | Update por loja+categoria reescreve período em todos os registros. AgregarMetricasCategoria:41-61. |
| Loja sem venda na janela recebe métricas zeradas/inativas | Não entra no loop; registro antigo permanece. AgregarMetricasCategoria:25-40. |
| Margem histórica é líquida de descontos e ponderada por unidade | AVG de margens unitárias por linha, ignora desconto/quantidade. AgregarMetricasCategoria:31-34; comparar RelatorioController:217. |
| Giro/razões existentes representam dados calculados de operação | Giro/planejada preenchidos em fixtures, não no job. TestarVarianciaPrecificacao:254-295. |
| Scheduler Laravel diário e worker Azure já configurados | Comando dispatchSync + criação opcional de Azure Job; ativação real não confirmada. routes/console.php:11-16; deploy-azure.sh:182-204. |
| Faixa financeira e flag demo persistidas | Não encontradas no schema de lojas; faixa usada no onboarding, loja laboratório sem flag. Migration lojas:11-34; OnboardingInferirRequest:22; TestarVarianciaPrecificacao:67-78. |
| Estoque histórico/data de entrada física disponível | Entradas tem schema sem fluxo de gravação encontrado; estoque editável diretamente e variantes recriadas. ProdutoController:69-75,122-130. |
| Cenário escolhido ainda não é registrado | Já é registrado na confirmação; origem no produto também. PrecificacaoController:171-181. |
| Toda mudança de preço mantém trilha/origem correta | PUT/store direto não registra escolha/origem. ProdutoController:57-67,112-120. |
| Cenário ativo tem vínculo forte com log da rodada | Produto só guarda preco_origem/preço, sem FK de log ativo. Migration produtos:14-31. |
| Reprecificação com IA já está exposta em produto existente | API suporta; frontend edição apenas PUT/redireciona. ProductForm.tsx:276-295. |
| Camada 4 ausente é chave omitida | Chave presente com null; comentário contradiz montagem. PrecificacaoPayloadService:45,132-134. |
| MargemLiquidacaoService/Recomendação/Camada 3 já existem | Não encontrados em app/routes/frontend; envelope só camadas 1/2/4. PrecificacaoPayloadService:42-52; prompt:30-53. |
| Modal/animações ainda precisam ser implementados do zero | Modal integrado; falta vincular uma animação central e não há protótipo original disponível. PricingModal.tsx:23-26,64; globals.css:29,38-48. |
| Seed/factories já fornecem massa multi-loja com vendas | Apenas seed/factory de usuário. DatabaseSeeder:16-23; UserFactory:25-33. |

## Bloqueios

Estimativas qualitativas de implementação/testes, **inferências**, não orçamento ou decisões aprovadas. Pequeno = mudança localizada; médio = integração de múltiplos fluxos/dados; grande = novo histórico ou pipeline com migração de dados. As fases abaixo são agrupamentos desta auditoria; a ordem detalhada dos planos não pôde ser conferida.

| Fase / pré-requisito | Bloqueio ou lacuna | Esforço | Evidência |
| --- | --- | --- | --- |
| Especificação de ambas as fases | Ler os dois planos para completar divergências e dependências exatas | Pequeno após disponibilização | Anexos não recebidos; busca documental descrita no início |
| Camada 3 — classificação | Taxonomia canônica, cardinalidade do vínculo e mapeamento das categorias/produtos existentes ausentes | Médio; curadoria da massa pode aumentar | Migration categorias:14-20; model Categoria:20-33 |
| Camada 3 — elegibilidade | Filtros de porte/amostra e exclusão confiável de laboratório/demo ausentes; distribuição real de lojas/vendas não auditada | Médio | Migration lojas:11-34; TestarVarianciaPrecificacao:67-99 |
| Camada 3 — cálculo e integração | Agregação entre semelhantes/faixa de mercado, armazenamento/versão/fallback e contrato camada_3 inexistentes | Médio | PrecificacaoPayloadService:42-52; AgregarMetricasCategoria:22-36 |
| Métricas base para recomendação | Giro real, margem planejada e candidatos não alimentados operacionalmente; definição de unidades/janela e política sem vendas necessária | Médio se puder usar vendas/cadastro; grande se exigir lotes/histórico de estoque | AgregarMetricasCategoria:46-54; migrations entradas:14-26 e itens:14-27 |
| Qualquer fase que exija estoque no fechamento | Não há ledger/snapshots e edições destroem identidade de variantes; histórico confiável não pode ser inferido retroativamente | Grande | ProdutoController:122-130; migration itens:25 |
| Qualquer fase que dependa de métricas válidas por janela | Persistência reescreve períodos, deixa categorias sem vendas com dados antigos e tem precisão de margem limitada | Médio | AgregarMetricasCategoria:25-61; migration métricas:20,28 |
| Operação periódica | Config de Azure Job existe, mas ativação/execução real precisa ser verificada; não presumir scheduler funcionando | Pequeno para verificação, variável se infraestrutura falhar | deploy-azure.sh:182-204 |
| Recomendação V1 — motor/contrato | Não há cenário recomendado, motivo estruturado, versão de regra nem registro da recomendação; dependências de sinais precisam ser definidas | Médio | migration logs:14-25; PrecificacaoIaPrompt:30-53 |
| Recomendação V1 — atribuição/resultados | Escolha já existe, mas log ativo e rastreio de preço manual/reprecificação são incompletos; venda não guarda cenário | Médio | PrecificacaoController:171-181; ProdutoController:112-120; migration itens:14-27 |
| Reprecificação no frontend | API reaproveitável, falta abrir rodada de IA/confirmar na edição de produto existente | Médio | ProductForm.tsx:276-295,652-655 |
| Apresentação da recomendação | Modal já pronto, mas não tem campo/badge de recomendação; animação central sem regra de classe | Pequeno a médio | PricingModal.tsx:7-12,99-131; globals.css:29,38-48 |
| Validação das duas fases | Factories/fixtures de vendas multi-loja e testes de agregação/fallback/isolamento ausentes | Médio | database/factories/UserFactory.php:13-33; testes descritos em G |

**Não é bloqueio de schema:** registrar cenário escolhido via confirmação, guardar preço manual nesse fluxo, venda datada por produto e preço/custo no momento já existem (PrecificacaoController:171-181; SaidaController:284-329). Essas peças podem ser reaproveitadas sem duplicar tabelas por nomes ilustrativos.

## Decisões de produto em aberto

Perguntas para o grupo; nenhuma foi resolvida nesta auditoria. Motivação técnica entre parênteses.

1. Categoria local deve mapear integralmente a uma subcategoria canônica ou cada produto deve poder ter classificação própria? Como tratar categorias existentes ambíguas e sem loja? (A1–A2; migration categorias:17.)
2. O que torna lojas semelhantes: posicionamento, faixa de faturamento, canal, região, gênero ou combinação? Quais faixas, amostra mínima, fallback e tratamento de outliers? A loja consultante entra na própria amostra? (D12; migration lojas:17-25.)
3. Faixa de mercado deve usar preço anunciado atual, preço efetivamente vendido, margem ou vários sinais? Qual janela, estatística, ponderação por loja/produto/unidade e efeito dos descontos? (B5; AgregarMetricasCategoria:29-34; C8.)
4. Como identificar/excluir lojas de laboratório/demo e dados sintéticos sem recorrer a nomes/emails? Dados compartilhados serão apresentados apenas agregados, com qual limiar de privacidade? (D12–D13; TestarVarianciaPrecificacao:67-78.)
5. “Giro em dias” significa tempo até primeira venda, média entre entrada e vendas, cobertura de estoque ou outro indicador? Qual data de origem, política sem vendas, reposições, devoluções e quantidades parciais? (B5, C10–C11.)
6. A recomendação V1 exige saldo no fechamento da janela ou aceita saldo atual? Precisa acompanhar lotes? Como lidar com dados históricos impossíveis de reconstruir? (C10; ProdutoController:122-130.)
7. Qual é a margem planejada histórica: meta da loja, margem do cenário escolhido no momento ou outra? Ela inclui frete, tributos, taxas e rateio fixo? O que fazer quando a meta/preço muda? (B6; PrecificacaoPayloadService:219-225; E15.)
8. O volume mínimo significa unidades, linhas de item, pedidos ou produtos distintos? Quanto tempo de histórico e qual validade de uma métrica antes do fallback? (B4–B5; AgregarMetricasCategoria:29,49.)
9. A recomendação escolhe entre os três cenários por regra determinística ou por IA? Quais prioridades/limiares e como explicar conflitos entre mercado, giro, margem e dados ausentes/desatualizados? (B6; PrecificacaoIaPrompt:37-53.)
10. A recomendação é apenas destaque ou seleção inicial? O lojista deve confirmar explicitamente? Pode manter preço atual, recusar recomendação ou informar preço abaixo do piso? (E16; PrecificacaoController:147,156-165; PricingModal.tsx:140-161.)
11. Qual trilha guardar: recomendação, versão, motivo, dados usados, rodada ativa, escolha, preço manual e motivo de discordância? Como vincular vendas ao cenário efetivamente em uso e lidar com reprecificação? (E15–E17; migration logs:14-25; migration itens:14-27.)
12. Quais mudanças disparam recálculo e quando ele aparece em produtos existentes? Pode sobrescrever preço automaticamente ou apenas sugerir? (E17; ProductForm.tsx:276-295.)
13. Qual protótipo do Claude Design é a referência final e qual grau de paridade visual/acessibilidade é exigido? (F20; PricingModal.tsx:23-26; globals.css:64-68.)

## Verificação da auditoria

Leitura estática com `rg`, inventário de arquivos e `nl -ba`; inspeção de migrations, models, requests, controllers, services, job, rotas, factories/seeders, testes, Docker/entrypoint/deploy e frontend. Não foram executados testes, build, artisan, scripts de deploy ou consultas de banco. Nenhuma afirmação deste relatório equivale a validação de runtime, Azure ou conteúdo do banco.

No início, a API já tinha alterações locais em `.dockerignore`, `.gitignore`, `Dockerfile`, `docker/entrypoint.sh`, `routes/console.php` e `deploy-azure.sh` não rastreado; foram preservadas. Frontend estava sem alterações reportadas por git. A auditoria considera esses arquivos como estão no workspace, não apenas HEAD. Único arquivo criado por esta auditoria: este relatório, sem commit.
