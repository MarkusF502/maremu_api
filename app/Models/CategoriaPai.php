<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaPai extends Model
{
    protected $table = 'categorias_pai';

    protected $fillable = ['nome', 'slug', 'google_taxonomy_id', 'ordem'];

    public function subCategorias(): HasMany
    {
        return $this->hasMany(SubCategoria::class)->orderBy('ordem')->orderBy('nome');
    }
}
