<?php

/*
| Parâmetros do job AgregarMetricasCategoria. São heurísticas iniciais,
| não valores calibrados: ficam aqui para poderem mudar sem tocar no código.
*/

return [
    // Horizonte, em dias, das métricas de referência (giro, margens, volume).
    'horizonte_dias' => 90,

    // Janela adaptativa por loja e categoria: clamp(giro × fator, min, max).
    'janela_fator_giro' => 0.25,
    'janela_min_dias' => 7,
    'janela_max_dias' => 30,

    // Unidades vendidas no horizonte para a camada 4 ser enviada à IA.
    'volume_minimo_unidades' => 1,
];
