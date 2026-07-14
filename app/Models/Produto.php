<?php

namespace App\Models;

use App\Models\Categoria;
use App\Models\VarianteProduto;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Produto extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'loja_id',
        'categoria_id',
        'nome',
        'sku',
        'custo_aquisicao',
        'frete_entrada_unitario',
        'preco_piso',
        'preco_venda_atual',
        'preco_origem',
        'status',
        'data_cadastro',
        'genero'
        
    ];

    protected $casts = [
        'custo_aquisicao' => 'decimal:2',
        'frete_entrada_unitario' => 'decimal:2',
        'preco_piso' => 'decimal:2',
        'preco_venda_atual' => 'decimal:2',
        'data_cadastro' => 'datetime',
        'genero' => 'string'
    ];

    public function loja(): BelongsTo
    {
        return $this->belongsTo(Loja::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(VarianteProduto::class);
    }
}