<?php

/*
|--------------------------------------------------------------------------
| Premissas dos dados de demonstração
|--------------------------------------------------------------------------
|
| Tudo que o DemoSeeder sorteia sai daqui. O arquivo existe para que a
| avaliação do TCC possa auditar de onde veio cada número: nenhum valor do
| seeder é inventado no código.
|
| Duas origens convivem, sempre marcadas:
|   - LEVANTAMENTO: preço observado em lojas online em 23/10/2026. Células
|     marcadas em `estimado` não foram observadas — foram extrapoladas da
|     mesma linha, porque a faixa premium de algumas peças não apareceu no
|     levantamento.
|   - PREMISSA: valor assumido, sem fonte. Margem bruta e volume de vendas
|     entram nesta categoria: são plausíveis para varejo de moda, mas não
|     foram medidos.
|
| Nenhum número daqui é usado pela camada 3 nem pela IA de precificação: eles
| só geram o catálogo e as vendas simuladas, e as métricas são calculadas em
| cima disso pelo job AgregarMetricasCategoria, como aconteceria em produção.
|
*/

return [

    /*
    | Semente do gerador aleatório. Fixa de propósito: rodar o seeder duas
    | vezes, ou em outra máquina, produz exatamente os mesmos preços, custos
    | e vendas. Trocar este número gera outro conjunto de dados, igualmente
    | válido — mas aí os valores do relatório do TCC mudam.
    */
    'semente' => 20261023,

    /*
    | LEVANTAMENTO (23/10/2026) — preço de tabela em reais, por sub-categoria
    | e posicionamento. É o centro em torno do qual o preço de cada produto é
    | sorteado; a mediana de preço que a camada 3 devolve para uma amostra
    | grande converge para este valor.
    |
    | `pai` e `sub` precisam casar exatamente com database/data/taxonomia.json.
    | `estimado` lista os posicionamentos cujo preço não foi observado.
    */
    'precos_base' => [
        [
            'pai' => 'Calças',
            'sub' => 'Geral',
            'popular' => 165.00,
            'medio' => 250.00,
            'premium' => 430.00,
            'estimado' => [],
        ],
        [
            'pai' => 'Camisetas e tops',
            'sub' => 'Geral',
            'popular' => 60.00,
            'medio' => 85.00,
            'premium' => 200.00,
            'estimado' => [],
        ],
        [
            'pai' => 'Vestidos',
            'sub' => 'Geral',
            'popular' => 130.00,
            'medio' => 220.00,
            'premium' => 430.00,
            'estimado' => [],
        ],
        [
            'pai' => 'Casacos',
            'sub' => 'Casacos e jaquetas',
            'popular' => 210.00,
            'medio' => 390.00,
            'premium' => 800.00,
            'estimado' => ['premium'],
        ],
        [
            'pai' => 'Roupas íntimas e meias',
            'sub' => 'Sutiãs',
            'popular' => 50.00,
            'medio' => 130.00,
            'premium' => 220.00,
            'estimado' => ['premium'],
        ],
        [
            'pai' => 'Roupas íntimas e meias',
            'sub' => 'Meias',
            'popular' => 12.00,
            'medio' => 13.00,
            'premium' => 25.00,
            'estimado' => ['premium'],
        ],
    ],

    /*
    | PREMISSA (sem fonte) — sub-categorias que ficaram fora do levantamento e
    | existem só para dar perfil ao cenário: as três de roupa íntima compõem a
    | loja especializada em lingerie, e "Casacos › Outros" é a categoria não
    | comparável da loja consulente (gorro, luva, cachecol).
    |
    | Nenhuma delas é consultada nos casos avaliados, então o valor exato não
    | muda nenhum resultado da camada 3.
    */
    'precos_premissa' => [
        ['pai' => 'Roupas íntimas e meias', 'sub' => 'Lingerie',       'popular' => 55.00, 'medio' => 145.00, 'premium' => 240.00],
        ['pai' => 'Roupas íntimas e meias', 'sub' => 'Meias-calças',   'popular' => 18.00, 'medio' => 45.00,  'premium' => 80.00],
        ['pai' => 'Roupas íntimas e meias', 'sub' => 'Roupas íntimas', 'popular' => 25.00, 'medio' => 60.00,  'premium' => 95.00],
        ['pai' => 'Casacos',                'sub' => 'Outros',         'popular' => 45.00, 'medio' => 70.00,  'premium' => 130.00],
    ],

    /*
    | PREMISSA (sem fonte) — preço do bazar da loja consulente, a categoria
    | deixada sem classificação de propósito para demonstrar o motivo
    | `categoria_sem_classificacao`. Sem sub-categoria, ela não tem linha na
    | tabela de preços.
    */
    'preco_bazar' => ['popular' => 40.00, 'medio' => 60.00, 'premium' => 110.00],

    /*
    | Variação do preço de cada produto em torno do preço base: ±20%.
    | É o que dá largura à distribuição — sem ela, p25, mediana e p75 de uma
    | loja seriam o mesmo número.
    */
    'variacao_preco' => 0.20,

    /*
    | PREMISSA (sem fonte) — margem bruta média por posicionamento, como
    | fração do preço de venda. Loja popular trabalha com margem menor e
    | volume maior; premium, o contrário.
    |
    | Custo de aquisição de cada produto = preço × (1 − margem), com a margem
    | sorteada em ±5 pontos percentuais em torno da média do posicionamento.
    | O frete de entrada fica em zero para que a margem efetiva do produto
    | seja exatamente a margem sorteada.
    */
    'margem_bruta' => [
        'popular' => 0.50,
        'medio' => 0.55,
        'premium' => 0.62,
    ],
    'variacao_margem' => 0.05,

    /*
    | PREMISSA (sem fonte) — unidades vendidas por produto em 90 dias, por
    | sub-categoria. Define o giro: o job calcula giro de um produto como
    | dias observados ÷ unidades vendidas, então 90 dias e 9 unidades dão um
    | giro de 10 dias.
    |
    | A ordem de grandeza segue o senso comum do varejo de moda: meias e
    | camisetas giram rápido, casaco de inverno gira devagar.
    */
    'vendas_90_dias' => [
        'Camisetas e tops|Geral' => [7, 12],
        'Roupas íntimas e meias|Meias' => [8, 15],
        'Roupas íntimas e meias|Sutiãs' => [4, 8],
        'Calças|Geral' => [3, 6],
        'Vestidos|Geral' => [2, 5],
        'Casacos|Casacos e jaquetas' => [1, 3],
    ],

    /*
    | Faixa usada para qualquer sub-categoria fora da tabela acima.
    */
    'vendas_90_dias_padrao' => [2, 5],

    /*
    | PREMISSA (sem fonte) — intensidade da categoria de giro rápido da loja
    | consulente. Acima da faixa normal de camisetas de propósito: a
    | recomendação de cenário precisa de uma categoria que claramente gira,
    | para contrastar com o produto encalhado.
    */
    'vendas_90_dias_giro_rapido' => [14, 20],

    /*
    | PREMISSA (sem fonte) — catálogo e estoque de cada categoria.
    */
    'produtos_por_categoria' => 4,
    'tamanhos' => ['P', 'M', 'G'],
    'estoque_por_tamanho' => [2, 9],

    /*
    | PREMISSA (sem fonte) — comportamento das vendas simuladas.
    | `desconto_a_cada` = 1 em N itens vendidos sai com desconto, de
    | `desconto_percentual` sobre o valor da linha.
    */
    'horizonte_vendas_dias' => 90,
    'dias_sem_venda_encalhado' => 78,
    'unidades_por_pedido' => [1, 2],
    'desconto_a_cada' => 5,
    'desconto_percentual' => 0.10,
    'canais' => ['loja_fisica', 'instagram_whatsapp', 'marketplace'],

    /*
    | Idade do cadastro de cada produto, em dias. Sempre acima do horizonte de
    | 90 dias para que o giro seja calculado sobre a janela cheia — produto
    | cadastrado ontem teria giro artificialmente baixo.
    */
    'idade_cadastro_dias' => [95, 200],
];
