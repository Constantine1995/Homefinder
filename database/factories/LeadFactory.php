<?php

namespace Database\Factories;

use App\Enums\LeadPayment;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state: a buyer who has told the budget and the rooms.
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
            'budget' => 9_000_000,
            'rooms' => [2],
        ];
    }

    /**
     * A buyer who knows what and how to buy and has asked for a viewing.
     */
    public function hot(): static
    {
        return $this->state(['payment' => LeadPayment::Mortgage, 'deadline_year' => 2027, 'wants_viewing' => true]);
    }
}
