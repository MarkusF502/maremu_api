<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VarianteProduto extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'variantes_produto';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'produto_id',
        'tamanho',
        'quantidade_estoque',
        'estoque_minimo_alerta',
    ];

    protected $casts = [
        'quantidade_estoque' => 'integer',
        'estoque_minimo_alerta' => 'integer',
    ];

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Produto::class);
    }
}