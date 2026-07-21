<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricasCategoriaLoja extends Model
{
    use HasUuids;

    protected $table = 'metricas_categoria_loja';

    protected $fillable = [
        'loja_id',
        'categoria_id',
        'periodo_referencia',
        'giro_medio_dias',
        'margem_realizada_media',
        'margem_planejada_media',
        'qtd_vendas_periodo',
        'volume_minimo_atingido',
        'candidatos_liquidacao',
        'data_calculo',
    ];

    protected $casts = [
        'periodo_referencia'     => 'date',
        'giro_medio_dias'        => 'decimal:2',
        'margem_realizada_media' => 'decimal:2',
        'margem_planejada_media' => 'decimal:2',
        'qtd_vendas_periodo'     => 'integer',
        'volume_minimo_atingido' => 'boolean',
        'candidatos_liquidacao'  => 'array',
        'data_calculo'           => 'datetime',
    ];

    public function loja(): BelongsTo
    {
        return $this->belongsTo(Loja::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }
}