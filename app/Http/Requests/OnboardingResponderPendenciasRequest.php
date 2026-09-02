<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida as respostas do wizard de pendências (SPEC-Extracao-Assertiva-
 * Onboarding-Maremu §8.2). Usada na rota
 * POST /api/loja/onboarding/responder-pendencias
 */
class OnboardingResponderPendenciasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sessao_id'            => ['required', 'uuid'],
            'respostas'            => ['required', 'array', 'min:1'],
            'respostas.*.id'       => ['required', 'string'],
            'respostas.*.resposta' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'sessao_id.required' => 'Sessão de onboarding não informada.',
            'respostas.required' => 'Nenhuma resposta informada.',
            'respostas.min'      => 'Nenhuma resposta informada.',
        ];
    }
}
