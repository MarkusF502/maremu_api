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
        'periodo_inicio',
        'janela_dias',
        'fechada',
        'giro_medio_dias',
        'margem_realizada_media',
        'margem_planejada_media',
        'qtd_vendas_periodo',
        'volume_minimo_atingido',
        'unidades_vendidas_janela',
        'vendas_por_dia',
        'preco_mediano',
        'margem_mediana',
        'n_produtos',
        'estoque_total',
        'candidatos_liquidacao',
        'desatualizada',
        'data_calculo',
    ];

    protected $casts = [
        'periodo_referencia'     => 'date',
        'giro_medio_dias'        => 'decimal:2',
        'periodo_inicio'         => 'date',
        'janela_dias'            => 'integer',
        'fechada'                => 'boolean',
        'margem_realizada_media' => 'decimal:4',
        'margem_planejada_media' => 'decimal:4',
        'unidades_vendidas_janela' => 'integer',
        'vendas_por_dia'         => 'decimal:4',
        'preco_mediano'          => 'decimal:2',
        'margem_mediana'         => 'decimal:4',
        'n_produtos'             => 'integer',
        'estoque_total'          => 'integer',
        'desatualizada'          => 'boolean',
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