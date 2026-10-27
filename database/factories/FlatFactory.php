<?php

namespace Database\Factories;

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use App\Models\Building;
use App\Models\Flat;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flat>
 */
class FlatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rooms = fake()->numberBetween(0, 4);
        $area = fake()->randomFloat(2, 20 + 18 * $rooms, 30 + 22 * $rooms);

        return [
            'building_id' => Building::factory(),
            'tenant_id' => fn (array $attributes): int => Building::withoutGlobalScope(TenantScope::class)
                ->findOrFail($attributes['building_id'])
                ->tenant_id,
            'external_id' => fake()->unique()->uuid(),
            'number' => (string) fake()->numberBetween(1, 500),
            'floor' => fake()->numberBetween(1, 25),
            'rooms' => $rooms,
            'is_euro' => $rooms > 0 && fake()->boolean(30),
            'area' => $area,
            'kitchen_area' => $rooms === 0 ? null : fake()->randomFloat(2, 8, 20),
            'finishing' => fake()->randomElement(Finishing::cases()),
            'price' => (int) round($area * fake()->numberBetween(250_000, 450_000), -4),
            'status' => FlatStatus::Available,
            'plan_url' => fake()->url(),
            'description' => fake()->realText(200),
        ];
    }
}
