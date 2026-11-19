<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogRecomendacaoCenario extends Model
{
    use HasUuids;

    protected $table = 'logs_recomendacao_cenario';

    protected $fillable = [
        'produto_id',
        'loja_id',
        'log_sugestao_ia_id',
        'cenario_recomendado',
        'origem_recomendacao',
        'motivo_indisponivel',
        'sinais',
        'versao_regras',
    ];

    protected $casts = [
        'sinais' => 'array',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }

    public function logSugestaoIa(): BelongsTo
    {
        return $this->belongsTo(LogsSugestaoIa::class);
    }
}
