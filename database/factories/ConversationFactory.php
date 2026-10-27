<?php

namespace Database\Factories;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
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
            'channel' => ConversationChannel::Test,
            'status' => ConversationStatus::Ai,
        ];
    }

    /**
     * A conversation with a buyer writing from the contact's channel.
     */
    public function withContact(?Contact $contact = null): static
    {
        return $this->state(function () use ($contact): array {
            $contact ??= Contact::factory()->create();

            return [
                'tenant_id' => $contact->tenant_id,
                'contact_id' => $contact->id,
                'channel' => ConversationChannel::from($contact->channel->type->value),
            ];
        });
    }

    /**
     * A conversation handed over to the managers.
     */
    public function handedOff(string $reason = 'покупатель просит менеджера'): static
    {
        return $this->state(['status' => ConversationStatus::Human, 'handoff_reason' => $reason]);
    }

    /**
     * A conversation the manager has taken.
     */
    public function takenBy(User $manager): static
    {
        return $this->state(['tenant_id' => $manager->tenant_id, 'status' => ConversationStatus::Human, 'assignee_id' => $manager->id]);
    }

    /**
     * A closed conversation.
     */
    public function closed(): static
    {
        return $this->state(['status' => ConversationStatus::Closed]);
    }
}
