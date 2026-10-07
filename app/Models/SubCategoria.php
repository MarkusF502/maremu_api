<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubCategoria extends Model
{
    protected $table = 'sub_categorias';

    protected $fillable = ['categoria_pai_id', 'nome', 'slug', 'google_taxonomy_id', 'comparavel', 'ordem'];

    protected $casts = [
        'comparavel' => 'boolean',
    ];

    public function categoriaPai(): BelongsTo
    {
        return $this->belongsTo(CategoriaPai::class);
    }
}
