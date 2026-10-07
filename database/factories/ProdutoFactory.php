<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Loja;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Produto>
 */
class ProdutoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $custo = $this->faker->randomFloat(2, 20, 150);

        return [
            'loja_id' => Loja::factory(),
            'categoria_id' => Categoria::factory(),
            'nome' => $this->faker->words(3, true),
            'custo_aquisicao' => $custo,
            'frete_entrada_unitario' => $this->faker->randomFloat(2, 5, 20),
            'preco_piso' => $custo * 1.3, // Estimativa simples para o piso
            'preco_venda_atual' => $custo * 2.0, // Estimativa simples para venda
            'status' => $this->faker->randomElement(['ativo', 'inativo', 'liquidacao']),
        ];
    }
}
