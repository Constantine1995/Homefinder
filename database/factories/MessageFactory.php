<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
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
            'role' => MessageRole::Buyer,
            'text' => 'двушка до 9 млн',
        ];
    }

    /**
     * A reply of the agent.
     */
    public function fromAgent(string $text = 'Подобрал две квартиры.'): static
    {
        return $this->state(['role' => MessageRole::Agent, 'text' => $text]);
    }

    /**
     * A message the manager wrote to the buyer.
     */
    public function fromManager(User $manager, string $text = 'Здравствуйте! Я менеджер, помогу с покупкой.'): static
    {
        return $this->state(['role' => MessageRole::Manager, 'user_id' => $manager->id, 'text' => $text]);
    }
}
