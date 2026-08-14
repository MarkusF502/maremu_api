<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogsOnboardingIa extends Model
{
    use HasUuids;

    protected $table = 'logs_onboarding_ia';

    protected $fillable = [
        'user_id',
        'loja_id',
        'texto_original',
        'dados_factuais',
        'estimativas_ia',
        'estimativas_finais',
        'usou_fallback',
        'motivo_fallback',
    ];

    protected $casts = [
        'dados_factuais'      => 'array',
        'estimativas_ia'      => 'array',
        'estimativas_finais'  => 'array',
        'usou_fallback'       => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loja(): BelongsTo
    {
        return $this->belongsTo(Loja::class);
    }
}
