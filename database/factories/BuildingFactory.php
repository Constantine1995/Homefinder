<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Complex;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'complex_id' => Complex::factory(),
            'tenant_id' => fn (array $attributes): int => Complex::withoutGlobalScope(TenantScope::class)
                ->findOrFail($attributes['complex_id'])
                ->tenant_id,
            'external_id' => fake()->unique()->uuid(),
            'name' => 'Корпус '.fake()->numberBetween(1, 12),
            'address' => fake()->address(),
            'latitude' => fake()->latitude(55.6, 55.9),
            'longitude' => fake()->longitude(37.4, 37.8),
            'floors' => fake()->numberBetween(9, 33),
            'deadline' => now()->addQuarters(fake()->numberBetween(0, 12))->endOfQuarter(),
        ];
    }

    /**
     * Indicate that the building came with an address but without coordinates, as spreadsheet rows often do.
     */
    public function unlocated(): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => null,
            'longitude' => null,
        ]);
    }
}
