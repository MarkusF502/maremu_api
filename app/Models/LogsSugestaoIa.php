<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogsSugestaoIa extends Model
{
    use HasUuids;

    protected $table = 'logs_sugestao_ia';

    protected $fillable = [
        'produto_id',
        'payload_enviado',
        'cenarios_retornados',
        'cenario_escolhido',
        'preco_final_escolhido',
    ];

    protected $casts = [
        'payload_enviado'      => 'array',
        'cenarios_retornados'  => 'array',
        'preco_final_escolhido' => 'decimal:2',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}