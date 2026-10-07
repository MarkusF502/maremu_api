<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Loja>
 */
class LojaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'                  => User::factory(),
            'nome'                     => $this->faker->company(),
            'posicionamento'           => $this->faker->randomElement(['popular', 'medio', 'premium']),
            'regime_tributario'        => 'simples_nacional',
            'faturamento_medio_mensal' => $this->faker->numberBetween(10000, 50000),
            'custo_fixo_mensal'        => $this->faker->numberBetween(2000, 8000),
            'margem_lucro_desejada'    => $this->faker->randomFloat(2, 0.25, 0.50),
            'aliquota_efetiva'         => $this->faker->randomFloat(3, 0.06, 0.12),
            'volume_vendas_esperado'   => $this->faker->numberBetween(100, 500),
        ];
    }
}
