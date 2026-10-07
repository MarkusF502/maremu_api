<?php

namespace Database\Seeders;

use App\Services\TaxonomiaService;
use Illuminate\Database\Seeder;

class TaxonomiaSeeder extends Seeder
{
    public function run(TaxonomiaService $taxonomia): void
    {
        $resultado = $taxonomia->sincronizar($taxonomia->lerArquivo());

        $this->command?->info("Taxonomia sincronizada: {$resultado['categorias_pai']} categorias-pai, {$resultado['sub_categorias']} sub-categorias.");
    }
}
