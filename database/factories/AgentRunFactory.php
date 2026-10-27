<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    /**
     * Define the model's default state.
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
            'intent' => 'search',
            'steps' => [],
            'actions' => [],
            'verified' => true,
            'problems' => [],
            'attempts' => 1,
            'prompt_tokens' => 1200,
            'completion_tokens' => 150,
            'duration_ms' => 4200,
        ];
    }
}
