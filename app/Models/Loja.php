<?php

namespace App\Models;

use App\Models\Categoria;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loja extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'nome',
        'posicionamento',
        'regime_tributario',
        'faturamento_medio_mensal',
        'custo_fixo_mensal',
        'custo_fixo_origem',
        'margem_lucro_desejada',
        'aliquota_efetiva',
        'aliquota_origem',
        'volume_vendas_esperado',
    ];

    protected $casts = [
        'faturamento_medio_mensal' => 'decimal:2',
        'custo_fixo_mensal'        => 'decimal:2',
        'margem_lucro_desejada'    => 'decimal:4',
        'aliquota_efetiva'         => 'decimal:4',
        'volume_vendas_esperado'   => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canais(): HasMany
    {
        return $this->hasMany(CanalVendaLoja::class);
    }

    public function canaisAtivos(): HasMany
    {
        return $this->hasMany(CanalVendaLoja::class)->where('ativo', true);
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class);
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(Produto::class);
    }
}