<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Loja;
use App\Models\User;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\LogsSugestaoIa;
use App\Models\MetricasCategoriaLoja;
use App\Services\PrecificacaoPayloadService;
use App\Services\GeminiService;
use Illuminate\Support\Str;

class TestarVarianciaPrecificacao extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'precificacao:testar-variancia
        {--amostras=20 : Número de chamadas à IA}
        {--cenario=longe_borda : Cenário de camada_4 a simular: longe_borda | borda_inferior_margem | borda_superior_margem | cold_start}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa a variância da IA (Condição B) realizando múltiplas chamadas e calculando o CV da margem.';

    public function handle(PrecificacaoPayloadService $payloadService, GeminiService $geminiService)
    {
        $amostras = (int) $this->option('amostras');
        
        $this->info("🎯 Iniciando teste de variância (Condição B - Margem Float) com {$amostras} amostras...");
        $this->warn("⏳ Isso pode levar alguns minutos devido aos limites de taxa da API (1.5s entre chamadas).");
        $this->warn("⚠️ ATENÇÃO: Os dados gerados neste teste serão SALVOS definitivamente no banco de dados.");

        try {
            // 1. Busca ou Cria o Usuário Fixo
            $user = User::firstOrCreate(
                ['email' => 'laboratorio_cv@maremu.com'],
                [
                    'name' => 'Lojista Teste Variancia',
                    'password' => bcrypt('password123'),
                ]
            );

            // 2. Busca ou Cria a Loja Fixa
            $loja = Loja::firstOrCreate(
                ['user_id' => $user->id, 'nome' => 'Loja Laboratório (CV)'],
                [
                    'posicionamento' => 'medio',
                    'regime_tributario' => 'simples_nacional',
                    'faturamento_medio_mensal' => 20000,
                    'custo_fixo_mensal' => 5000,
                    'margem_lucro_desejada' => 0.30,
                    'aliquota_efetiva' => 0.109,
                    'volume_vendas_esperado' => 120,
                ]
            );

            // 3. Busca ou Cria a Categoria Fixa
            $categoria = Categoria::firstOrCreate(
                ['loja_id' => $loja->id, 'nome' => 'calças']
            );

            // 4. Busca ou Cria o Produto Fixo
            $produto = Produto::firstOrCreate(
                [
                    'loja_id' => $loja->id,
                    'categoria_id' => $categoria->id,
                    'nome' => 'calça cargo'
                ],
                [
                    'genero' => 'unissex',
                    'custo_aquisicao' => 40.00,
                    'frete_entrada_unitario' => 10.00,
                    'preco_venda_atual' => 100.00,
                    'preco_piso' => 65.00,
                    'status' => 'ativo',
                ]
            );

            $cenario = $this->option('cenario');
            $this->info("📊 Cenário de camada_4: {$cenario}");
            $this->aplicarFixtureCamada4($produto, $categoria, $loja, $cenario);

            $payload = $payloadService->montar($produto);

            // Confere no payload de fato montado se camada_4/razao_margem/razao_giro
            // saíram como esperado — evita repetir o problema do teste anterior,
            // em que a fixture não chegava até o payload por engano silencioso.
            $razaoMargem = data_get($payload, 'camada_4.razao_margem');
            $razaoGiro   = data_get($payload, 'camada_4.razao_giro');
            $this->line("   razao_margem no payload: " . ($razaoMargem ?? 'ausente'));
            $this->line("   razao_giro no payload: " . ($razaoGiro ?? 'ausente'));
            $this->newLine();

            $margens = [
                'liquidacao' => [],
                'ideal' => [],
                'alta_demanda' => []
            ];
            
            $bar = $this->output->createProgressBar($amostras);
            $bar->start();

            for ($i = 0; $i < $amostras; $i++) {
                // Chama a IA
                $resultadoIa = $geminiService->sugerirCenarios($payload);

                // --- CORREÇÃO AQUI: SALVAR O LOG NO BANCO ---
                LogsSugestaoIa::create([
                    'id' => Str::uuid()->toString(),
                    'produto_id' => $produto->id,
                    'payload_enviado' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'cenarios_retornados' => json_encode($resultadoIa['cenarios'], JSON_UNESCAPED_UNICODE),
                    // cenario_escolhido e preco_final_escolhido ficam nulos por enquanto
                ]);

                // Armazena as margens de TODOS os cenários para o cálculo de CV
                foreach ($resultadoIa['cenarios'] as $cenarioResultado) {
                    $tipo = $cenarioResultado['tipo'] ?? 'desconhecido';
                    
                    if (isset($margens[$tipo]) && isset($cenarioResultado['margem_lucro_percentual'])) {
                        $margens[$tipo][] = (float) $cenarioResultado['margem_lucro_percentual'];
                    }
                }

                $bar->advance();

                // Pausa de 5 segundos para respeitar o Rate Limit (max 15 RPM)
                sleep(5); 
            }

            $bar->finish();
            $this->newLine(2);

            $resultados = [];
            $maiorCv = 0.0;

            foreach ($margens as $tipo => $amostrasCenario) {
                $coletas = count($amostrasCenario);
                
                if ($coletas < 2) {
                    $this->error("Amostras insuficientes para o cenário: {$tipo}");
                    continue;
                }

                // Média (Mean)
                $media = array_sum($amostrasCenario) / $coletas;

                // Desvio Padrão Amostral (Standard Deviation)
                $varianciaAmostral = 0.0;
                foreach ($amostrasCenario as $margem) {
                    $varianciaAmostral += pow($margem - $media, 2);
                }
                $varianciaAmostral /= ($coletas - 1);
                $desvioPadrao = sqrt($varianciaAmostral);

                // Coeficiente de Variação (CV)
                $cv = ($media > 0) ? ($desvioPadrao / $media) * 100 : 0;
                
                if ($cv > $maiorCv) {
                    $maiorCv = $cv;
                }

                $resultados[$tipo] = [
                    'coletas' => $coletas,
                    'media' => $media,
                    'desvio' => $desvioPadrao,
                    'cv' => $cv,
                    'bruto' => $amostrasCenario
                ];
            }

            $this->info("=== RESULTADOS DA PESQUISA (CONDIÇÃO B - MARGEM FLOAT) ===");
            
            foreach ($resultados as $tipo => $stats) {
                $this->warn(strtoupper("CENÁRIO: {$tipo}"));
                $this->line("Amostras válidas: {$stats['coletas']}");
                $this->line("Média da Margem: " . number_format($stats['media'] * 100, 2) . "%");
                $this->line("Desvio Padrão: " . number_format($stats['desvio'] * 100, 2) . "%");
                $this->line("Coeficiente de Variação (CV): " . number_format($stats['cv'], 2) . "%");
                $this->line("Distribuição bruta: [" . implode(', ', array_map(fn($m) => number_format($m*100, 1).'%', $stats['bruto'])) . "]");

                // Histograma simples por valor arredondado — CV agregado sozinho
                // não distingue "hesitação numa fronteira" (poucos valores distintos,
                // repetidos, batendo perto de um limiar) de "decisão instável"
                // (valores espalhados sem padrão). Isso importa especialmente para
                // o cenário 'liquidacao' nos testes de borda.
                $frequencias = [];
                foreach ($stats['bruto'] as $m) {
                    $chave = number_format($m * 100, 1) . '%';
                    $frequencias[$chave] = ($frequencias[$chave] ?? 0) + 1;
                }
                arsort($frequencias);
                $valoresDistintos = count($frequencias);
                $this->line("Valores distintos: {$valoresDistintos} → " . implode(', ', array_map(
                    fn($v, $c) => "{$v} ({$c}x)",
                    array_keys($frequencias),
                    array_values($frequencias)
                )));

                if ($tipo === 'liquidacao' && $valoresDistintos <= 3 && $stats['cv'] >= 5.0) {
                    $this->comment("   → Poucos valores distintos + CV alto: parece hesitação entre faixas vizinhas (efeito de borda), não decisão livre e dispersa.");
                    $this->comment("   → Se este teste usou --cenario=borda_inferior_margem ou borda_superior_margem, isso é esperado; compare com uma rodada --cenario=longe_borda para confirmar.");
                }

                $this->newLine();
            }

            $this->info("=== CRITÉRIO DE DECISÃO (Avaliando pelo pior CV: " . number_format($maiorCv, 2) . "%) ===");
            if ($maiorCv < 2.0) {
                $this->info("🎯 Resultado: H1 CONFIRMADA");
                $this->line("O maior CV entre todos os cenários é menor que 2%. A IA está gerando margens consistentes em todas as estratégias.");
            } elseif ($maiorCv >= 5.0) {
                $this->error("🚨 Resultado: H2 CONFIRMADA");
                $this->line("Pelo menos um dos cenários atingiu um CV igual ou superior a 5%. O modelo não converge estrategicamente para a margem em todos os contextos.");
                $this->line("A variância real reside na decisão livre da IA (float).");
                $this->line("Próximo passo: Implementar a Condição C (Faixas Discretas via Enum).");
            } else {
                $this->warn("⚠️ Resultado: INCONCLUSIVO");
            }

        } catch (\Exception $e) {
            $this->error("\nErro durante a execução: " . $e->getMessage());
        }
    }

    /**
     * Monta (ou remove) o registro de MetricasCategoriaLoja conforme o
     * cenário escolhido, para exercitar razao_margem/razao_giro em pontos
     * específicos — longe de qualquer borda das faixas do prompt, ou
     * propositalmente em cima delas (0.69 vs 0.71), para separar "hesitação
     * de fronteira" de "variância real de decisão".
     *
     * loja.volume_vendas_esperado = 120 (fixture fixa) → giro_esperado_dias
     * = 30 / (120/30) = 7.5 dias. giro_medio_dias abaixo é escolhido para
     * manter razao_giro = 1.0 (neutro) nos cenários de margem, isolando o
     * sinal que está sendo testado.
     */
    private function aplicarFixtureCamada4(Produto $produto, Categoria $categoria, Loja $loja, string $cenario): void
    {
        // Sempre remove fixture anterior primeiro — evita um cenário
        // "cold_start" rodar em cima de uma metrica deixada por um teste
        // anterior e mentir sobre o que está sendo testado.
        MetricasCategoriaLoja::where('loja_id', $loja->id)
            ->where('categoria_id', $categoria->id)
            ->delete();

        if ($cenario === 'cold_start') {
            return; // sem fixture — camada_4 fica ausente, como no fluxo original
        }

        $margemPlanejada = 0.30;
        $giroEsperadoDias = 30 / ($loja->volume_vendas_esperado / 30); // 7.5 com volume=120

        $fixtures = [
            // razao_margem = 0.30 / 0.30... na verdade queremos bem abaixo de 0.70,
            // e razao_giro = 3.0 (bem acima de 1.5) — os dois sinais concordam,
            // longe de qualquer fronteira. Caso de referência "sem ambiguidade".
            'longe_borda' => [
                'margem_realizada_media' => round($margemPlanejada * 0.30, 4),
                'giro_medio_dias'        => round($giroEsperadoDias * 3.0, 2),
            ],
            // razao_margem = 0.69 — logo abaixo do corte 0.70 (agressivo).
            // razao_giro = 1.0 (neutro) para isolar o sinal de margem.
            'borda_inferior_margem' => [
                'margem_realizada_media' => round($margemPlanejada * 0.69, 4),
                'giro_medio_dias'        => round($giroEsperadoDias * 1.0, 2),
            ],
            // razao_margem = 0.71 — logo acima do corte 0.70 (moderado/na meta).
            'borda_superior_margem' => [
                'margem_realizada_media' => round($margemPlanejada * 0.71, 4),
                'giro_medio_dias'        => round($giroEsperadoDias * 1.0, 2),
            ],
        ];

        if (! isset($fixtures[$cenario])) {
            throw new \InvalidArgumentException(
                "Cenário '{$cenario}' desconhecido. Use: " . implode(', ', array_keys($fixtures)) . ", cold_start."
            );
        }

        MetricasCategoriaLoja::create([
            'id'                      => Str::uuid()->toString(),
            'loja_id'                 => $loja->id,
            'categoria_id'            => $categoria->id,
            'periodo_referencia'      => now()->startOfMonth(),
            'volume_minimo_atingido'  => true,
            'giro_medio_dias'         => $fixtures[$cenario]['giro_medio_dias'],
            'margem_realizada_media'  => $fixtures[$cenario]['margem_realizada_media'],
            'margem_planejada_media'  => $margemPlanejada,
            'qtd_vendas_periodo'      => 20,
            'data_calculo'            => now(),
            'desatualizada'           => false,
        ]);
    }
}