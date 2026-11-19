<?php

/*
| Recomendação de cenário (App\Services\RecomendacaoCenarioService).
|
| Os limiares abaixo são HEURÍSTICA INICIAL, SEM CALIBRAÇÃO EMPÍRICA: foram
| escolhidos para a V1 por bom senso, não medidos em dados de venda. Mudar
| qualquer um deles muda a regra, então mude também `versao_regras`, para a
| taxa de aceitação (php artisan recomendacao:aceitacao) separar as versões.
*/
return [
    'versao_regras' => 'v1',

    // Liquidação quando dias sem venda ≥ fator × giro de referência.
    'fator_dias_sem_venda' => 2,

    // Abaixo disso, nos últimos 90 dias, o giro do produto não é confiável
    // e não há recomendação pela razão de giro.
    'minimo_unidades_90d' => 3,

    // razão = giro do produto ÷ giro de referência (dias por peça).
    // ≥ este valor: o produto gira bem mais devagar que a categoria.
    'razao_liquidacao' => 1.5,
    // ≤ este valor: o produto gira bem mais rápido que a categoria.
    'razao_alta_demanda' => 0.6,

    // Referência própria só vale com pelo menos tantos produtos da categoria
    // vendendo no horizonte, e com o registro de métricas recente.
    'minimo_produtos_com_venda' => 5,
    'idade_maxima_metrica_dias' => 90,
];
