# Margem líquida

Implementado na branch `margem-liquida`, criada da `main`, sem push ou deploy.
Identidade dos commits: MarkusF502 <markusfilipe502@gmail.com>, conforme o histórico recente e a configuração local.

## Alterações

1. Onboarding: prompt, schema e processamento dos provedores deixam de extrair margem. A margem é perguntada diretamente, mesmo se o texto mencionar percentual ou preços de compra e venda. A decomposição por preços foi removida; faturamento continua usando quantidade e ticket médio. Origem da margem confirmada: `editado_pelo_lojista`.
2. Validação: `MargemLiquida::RANGE` define 0,01 a 0,60 para onboarding tradicional, confirmação IA, configurações, guardrail e simulação. Na resposta à pendência, a unidade continua percentual (1 a 60); na persistência, fração decimal. Sugestões de 25%, 35% e 50% são premissas sem fonte, a revisar.
3. Simulação: cálculo e composição ficam no `PricingEngine`. A maior taxa ativa é compartilhada com a precificação por `TaxaCanalPrecificacao`.
4. Motor: divisor e multiplicador recebem somente alíquota, taxa e margem. As validações de volume e custo ficam em `calcularPreco`, antes da divisão. Os dois testes antigos agora verificam divisor 0,61, preço 103,28 e piso 69,23, calculados à mão.

O prompt de precificação também explicita as bases: margem desejada e cenários são líquidos; margens históricas realizada/planejada da camada 4 são brutas e sua razão é apenas um sinal relativo. Os nomes existentes no payload foram preservados.

## Contrato da simulação

`POST /api/precificacao/simular`, com `auth:sanctum`, sem `EnsureLojaExists` e sem chamadas à IA.

| Campo | Regra | Quando omitido |
| --- | --- | --- |
| `margem` | obrigatório, número de 0,01 a 0,60 | 422 |
| `custo_base` | número maior que zero | 50,00 |
| `custo_fixo_mensal` | número não negativo | loja; 422 sem loja |
| `volume_vendas_esperado` | inteiro maior que zero | loja; 422 sem loja |
| `aliquota_efetiva` | número de 0 a 1 | loja; 422 sem loja |
| `taxa_canal` | número de 0 a 1 | maior taxa de canal ativo; 422 se indisponível |

Valores explícitos prevalecem sobre a loja. `null` é inválido, não equivale a omissão. A simulação não grava dados.

Exemplo de entrada:

```json
{
  "margem": 0.20,
  "custo_base": 50.00,
  "custo_fixo_mensal": 2700.00,
  "volume_vendas_esperado": 180,
  "aliquota_efetiva": 0.06,
  "taxa_canal": 0.035
}
```

Resposta 200:

```json
{
  "viavel": true,
  "preco": 92.20,
  "preco_piso": 71.82,
  "multiplo_do_custo": 1.84,
  "lucro_por_peca": 18.44,
  "lucro_a_cada_100": 20.00,
  "composicao": {
    "preco_piso": 71.82,
    "custo_base": 50.00,
    "custo_fixo_unitario": 15.00,
    "percentual_variavel": 0.095
  }
}
```

Os valores do exemplo original do pedido não correspondem à fórmula. Foi mantido o motor: 65 / 0,705 = 92,20; piso = 65 / 0,905 = 71,82. Múltiplo e lucro por peça usam o preço retornado, arredondado em centavos. Números JSON não garantem zeros finais.

Se alíquota + taxa + margem >= 1, retorna 200:

```json
{"viavel": false, "motivo": "margem_inviavel", "margem_maxima": 0.4}
```

Nesse exemplo, alíquota é 0,4 e taxa é 0,2. O limite é matemático e exclusivo (igualá-lo já é inviável), truncado para baixo a seis casas decimais. Não é limitado ao teto cadastral de 60%. Campos ausentes/inválidos retornam 422 com `errors` por campo; ausência de autenticação retorna 401.

## Verificação

- Linha de base: 220 passaram, 10 falharam.
- Suíte completa após implementação: 232 passaram, 8 falharam (799 assertions).
- Os 28 testes do `PricingEngineTest` passaram, incluindo os dois corrigidos.
- Simulação e composição: 16 passaram; onboarding e validação de margem: 85 passaram.
- `git diff --check` sem erros.

Permanecem os mesmos oito testes antigos:

- `PrecificacaoControllerTest::test_monta_payload_e_cria_log_quando_preco_piso_existe`: não simula o provedor; agora a requisição externa é bloqueada e retorna 502 (na linha de base tentava acessar Gemini e falhou por DNS, com 500).
- `PrecificacaoControllerTest::test_confirmar_atualiza_preco_e_origem_do_produto`: recebe 422 ao esperar 200.
- `RelatorioControllerTest::test_retorna_resumo_zerado_quando_loja_nao_possui_produtos`.
- `RelatorioControllerTest::test_calcula_lucro_unitario_e_percentual_corretamente`.
- `RelatorioControllerTest::test_gera_alerta_quando_estoque_igual_ou_abaixo_do_minimo`.
- `RelatorioControllerTest::test_nao_gera_alerta_quando_estoque_acima_do_minimo`.
- `RelatorioControllerTest::test_curva_abc_agrupa_itens_excedentes_em_outros`.
- `RelatorioControllerTest::test_usa_lucro_potencial_por_categoria_quando_nao_ha_vendas_registradas`.

Os seis testes de relatório usam `/api/relatorios`, recebendo 404 (a rota existente é `/api/relatorio`). Não foram alterados nesta tarefa.

Foi adicionado `Http::preventStrayRequests()` à classe base dos testes após descobrir a tentativa externa da linha de base. Nenhuma chamada de IA foi concluída; as execuções seguintes bloqueiam HTTP não simulado. O comando `precificacao:testar-variancia` não foi executado. A tradução visual em tempo real no frontend fica com o consumidor deste endpoint, conforme o escopo da tarefa no `maremu-api`.
