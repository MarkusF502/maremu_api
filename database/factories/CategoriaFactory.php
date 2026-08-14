<?php

namespace Database\Factories;

use App\Models\Loja;
use App\Models\Categoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Categoria>
 */
class CategoriaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'loja_id' => Loja::factory(),
            'nome' => $this->faker->unique()->words(2, true),
        ];
    }
}
