<?php

namespace Database\Factories;

use App\Enums\FeedImportStatus;
use App\Models\FeedImport;
use App\Models\FeedSource;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedImport>
 */
class FeedImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feed_source_id' => FeedSource::factory(),
            'tenant_id' => fn (array $attributes): int => FeedSource::withoutGlobalScope(TenantScope::class)
                ->findOrFail($attributes['feed_source_id'])
                ->tenant_id,
            'status' => FeedImportStatus::Completed,
            'added_count' => fake()->numberBetween(0, 50),
            'updated_count' => fake()->numberBetween(0, 50),
            'removed_count' => fake()->numberBetween(0, 10),
            'failed_count' => 0,
            'finished_at' => now(),
        ];
    }

    /**
     * Indicate that the import has started and has not finished.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => FeedImportStatus::Running,
            'added_count' => 0,
            'updated_count' => 0,
            'removed_count' => 0,
            'finished_at' => null,
        ]);
    }
}
