<?php

namespace Database\Factories;

use App\Enums\HousingClass;
use App\Models\Complex;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Complex>
 */
class ComplexFactory extends Factory
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
            'external_id' => fake()->unique()->uuid(),
            'name' => 'ЖК «'.fake()->randomElement(['Северный парк', 'Речной квартал', 'Солнечный', 'Лесная поляна', 'Новые горизонты']).'»',
            'address' => fake()->address(),
            'latitude' => fake()->latitude(55.6, 55.9),
            'longitude' => fake()->longitude(37.4, 37.8),
            'deadline' => now()->addQuarters(fake()->numberBetween(0, 12))->endOfQuarter(),
            'housing_class' => fake()->randomElement(HousingClass::cases()),
            'description' => fake()->realText(200),
        ];
    }
}
