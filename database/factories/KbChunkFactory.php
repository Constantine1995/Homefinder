<?php

namespace Database\Factories;

use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KbChunk>
 */
class KbChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kb_document_id' => KbDocument::factory(),
            'tenant_id' => fn (array $attributes): int => KbDocument::withoutGlobalScope(TenantScope::class)
                ->findOrFail($attributes['kb_document_id'])
                ->tenant_id,
            'position' => 0,
            'section' => 'Паркинг',
            'text' => fake()->realText(300),
        ];
    }
}
