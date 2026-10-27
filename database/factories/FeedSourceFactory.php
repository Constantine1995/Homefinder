<?php

namespace Database\Factories;

use App\Enums\FeedType;
use App\Models\FeedSource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeedSource>
 */
class FeedSourceFactory extends Factory
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
            'name' => 'Фид Яндекс Недвижимости',
            'type' => FeedType::Yandex,
            'url' => fake()->url(),
            'schedule' => '0 * * * *',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the source is switched off and is not imported on schedule.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the source is imported only when someone asks for it.
     */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'schedule' => null,
        ]);
    }

    /**
     * Indicate that the source is a spreadsheet uploaded through the cabinet rather than a feed at a URL.
     */
    public function uploaded(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Шахматка',
            'type' => FeedType::Csv,
            'url' => null,
        ]);
    }
}
