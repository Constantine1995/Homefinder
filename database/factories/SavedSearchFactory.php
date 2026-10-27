<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\SavedSearch;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedSearch>
 */
class SavedSearchFactory extends Factory
{
    /**
     * Define the model's default state: a test-chat buyer waiting for a two-room flat up to 9 million.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'tenant_id' => fn (array $attributes): int => Conversation::withoutGlobalScope(TenantScope::class)
                ->findOrFail($attributes['conversation_id'])
                ->tenant_id,
            'contact_id' => fn (array $attributes): ?int => Conversation::withoutGlobalScope(TenantScope::class)
                ->findOrFail($attributes['conversation_id'])
                ->contact_id,
            'description' => '2-комн., до 9 млн ₽',
            'filters' => ['rooms' => [2], 'price_to' => 9_000_000, 'statuses' => ['available']],
        ];
    }

    /**
     * A subscription the buyer ended.
     */
    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
