<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'tenant_id' => fn (array $attributes): int => Channel::withoutGlobalScope(TenantScope::class)
                ->findOrFail($attributes['channel_id'])
                ->tenant_id,
            'channel_user_id' => (string) fake()->unique()->numberBetween(100_000_000, 999_999_999),
            'name' => fake('ru_RU')->firstName(),
            'consent_at' => now(),
        ];
    }

    /**
     * A buyer who has not agreed to the processing of personal data yet.
     */
    public function withoutConsent(): static
    {
        return $this->state(['consent_at' => null]);
    }

    /**
     * A buyer who shared a phone number.
     */
    public function withPhone(string $phone = '+79161234567'): static
    {
        return $this->state(['phone' => $phone]);
    }
}
