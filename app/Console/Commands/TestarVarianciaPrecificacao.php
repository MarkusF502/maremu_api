<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Loja;
use App\Models\User;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\LogsSugestaoIa;
use App\Services\PrecificacaoPayloadService;
use App\Services\PrecificacaoIaInterface;
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
        {--provider=gemini : Provedor de IA a testar: gemini | anthropic | openai}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa a variância da IA (Condição B) realizando múltiplas chamadas e calculando o CV da margem.';

    public function handle(PrecificacaoPayloadService $payloadService)
    {
        $amostras = (int) $this->option('amostras');
        $provider = $this->option('provider');

        if (! in_array($provider, ['gemini', 'anthropic', 'openai'], true)) {
            $this->error("Provider '{$provider}' desconhecido. Use: gemini, anthropic, openai.");

            return;
        }

        // Sobrescreve a config em runtime (sem editar .env nem reiniciar a
        // aplicação) e resolve a interface DEPOIS da sobrescrita, para que o
        // binding condicional de AppServiceProvider escolha o serviço certo.
        config(['services.ia_provider' => $provider]);
        $iaService = $this->laravel->make(PrecificacaoIaInterface::class);

        $this->info("🎯 Iniciando teste de variância (Condição B - Margem Float) com {$amostras} amostras (provider: {$provider})...");
        $this->warn("⏳ Isso pode levar alguns minutos devido aos limites de taxa da API (5s entre chamadas).");
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

            $payload = $payloadService->montar($produto);

            $margens = [
                'liquidacao' => [],
                'ideal' => [],
                'alta_demanda' => []
            ];
            
            $bar = $this->output->createProgressBar($amostras);
            $bar->start();

            for ($i = 0; $i < $amostras; $i++) {
                // Chama a IA
                $resultadoIa = $iaService->sugerirCenarios($payload);

                LogsSugestaoIa::create([
                    'id' => Str::uuid()->toString(),
                    'produto_id' => $produto->id,
                    'payload_enviado' => $payload,
                    'provedor_ia' => $iaService->identificador(),
                    'cenarios_retornados' => $resultadoIa['cenarios'],
                    // cenario_escolhido e preco_final_escolhido ficam nulos por enquanto
                ]);

                // Armazena as margens de TODOS os cenários para o cálculo de CV
                foreach ($resultadoIa['cenarios'] as $cenario) {
                    $tipo = $cenario['tipo'] ?? 'desconhecido';
                    
                    if (isset($margens[$tipo]) && isset($cenario['margem_lucro_percentual'])) {
                        $margens[$tipo][] = (float) $cenario['margem_lucro_percentual'];
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
}