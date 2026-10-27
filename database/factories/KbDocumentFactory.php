<?php

namespace Database\Factories;

use App\Models\KbDocument;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KbDocument>
 */
class KbDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = "# Паркинг\n\n".fake()->realText(300);

        return [
            'tenant_id' => Tenant::factory(),
            'complex_id' => null,
            'title' => 'Паркинг',
            'source' => fake()->unique()->url(),
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'index_version' => 1,
        ];
    }
}
