<?php

namespace Database\Factories;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $botId = fake()->unique()->numberBetween(1_000_000_000, 9_999_999_999);

        return [
            'tenant_id' => Tenant::factory(),
            'type' => ChannelType::Telegram,
            'name' => '@homefinder_demo_bot',
            'external_id' => (string) $botId,
            'credentials' => ['token' => "{$botId}:test-token", 'webhook_secret' => 'test-webhook-secret'],
            'settings' => ['policy_url' => 'https://developer.test/privacy'],
            'is_active' => true,
        ];
    }

    /**
     * The chat widget on the developer's site, known by its public key.
     */
    public function widget(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ChannelType::Widget,
            'name' => 'Виджет на сайте',
            'external_id' => Str::random(32),
            'credentials' => [],
        ]);
    }

    /**
     * Indicate that the developer switched the channel off.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
