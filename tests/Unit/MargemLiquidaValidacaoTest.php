<?php

namespace Tests\Unit;

use App\Http\Requests\OnboardingIaConfirmarRequest;
use App\Http\Requests\OnboardingResponderPendenciasRequest;
use App\Http\Requests\OnboardingSalvarRequest;
use App\Services\OnboardingGuardrail;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class MargemLiquidaValidacaoTest extends TestCase
{
    public function test_mesmos_limites_no_onboarding_configuracoes_e_guardrail(): void
    {
        foreach ([0, 0.009, 0.01, 0.03, 0.60, 0.601] as $margem) {
            $valida = $margem >= 0.01 && $margem <= 0.60;
            foreach ([new OnboardingSalvarRequest, new OnboardingIaConfirmarRequest] as $request) {
                $validator = Validator::make(['margem_lucro_desejada' => $margem], [
                    'margem_lucro_desejada' => $request->rules()['margem_lucro_desejada'],
                ]);
                $this->assertSame($valida, $validator->passes());
            }
            $resultado = (new OnboardingGuardrail)->clampar('margem_lucro_desejada', $margem);
            $this->assertSame(! $valida, $resultado['clampado']);
        }
    }

    public function test_pendencia_valida_percentual_sem_clampar_resposta_do_lojista(): void
    {
        foreach ([0, 1, 3, 60, 61, 'confirmado', null] as $valor) {
            $request = new OnboardingResponderPendenciasRequest;
            $request->merge(['respostas' => [['id' => 'margem_lucro_desejada', 'resposta' => $valor]]]);
            $rules = $request->rules();
            $this->assertSame(is_numeric($valor) && $valor >= 1 && $valor <= 60,
                Validator::make($request->all(), ['respostas.0.resposta' => $rules['respostas.0.resposta']])->passes());
        }
    }
}
