<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanalVendaLoja extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'canais_venda_loja';

    protected $fillable = [
        'loja_id',
        'canal',
        'taxa_percentual',
        'taxa_origem',
        'ativo',
    ];

    protected $casts = [
        'taxa_percentual' => 'decimal:4',
        'ativo'           => 'boolean',
    ];

    public function loja(): BelongsTo
    {
        return $this->belongsTo(Loja::class);
    }
}