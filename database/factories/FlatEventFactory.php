<?php

namespace Database\Factories;

use App\Enums\FlatEventField;
use App\Models\Flat;
use App\Models\FlatEvent;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FlatEvent>
 */
class FlatEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $oldPrice = fake()->numberBetween(50, 200) * 100_000;

        return [
            'flat_id' => Flat::factory(),
            'tenant_id' => fn (array $attributes): int => Flat::withoutGlobalScope(TenantScope::class)
                ->findOrFail($attributes['flat_id'])
                ->tenant_id,
            'field' => FlatEventField::Price,
            'old_value' => $oldPrice,
            'new_value' => $oldPrice - fake()->numberBetween(1, 10) * 100_000,
        ];
    }
}
