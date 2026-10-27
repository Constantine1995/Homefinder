<?php

namespace Database\Factories;

use App\Models\MortgageProgram;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MortgageProgram>
 */
class MortgageProgramFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement(['Семейная ипотека', 'Стандартная ипотека', 'IT-ипотека']),
            'bank' => 'Банк '.fake()->lastName(),
            'rate' => fake()->randomFloat(2, 6, 20),
            'min_down_pct' => 20,
            'max_amount' => 12_000_000,
            'max_years' => 30,
            'conditions' => fake()->realText(120),
            'valid_to' => null,
        ];
    }
}
