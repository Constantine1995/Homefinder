<?php

use App\Models\KbChunk;
use App\Models\KbDocument;
use App\Models\Tenant;
use App\Services\Search\KnowledgeEmbedder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('stores the vectors of the chunks without one, asking the agent service tenant by tenant', function () {
    Http::preventStrayRequests();
    $tenant = Tenant::factory()->create();
    $chunk = KbChunk::factory()->for(KbDocument::factory()->for($tenant), 'document')->create();
    $otherChunk = KbChunk::factory()->create();
    $embedded = KbChunk::factory()->for(KbDocument::factory()->for($tenant), 'document')->create(['embedding' => array_fill(0, 1024, 0.25)]);
    Http::fake(['agent.invalid/v1/knowledge/embeddings' => fn (Request $request) => Http::response([
        'model' => 'ollama/bge-m3',
        'chunks' => array_map(fn (int $id): array => ['id' => $id, 'embedding' => array_fill(0, 1024, 0.5)], $request['chunk_ids']),
    ])]);

    expect(app(KnowledgeEmbedder::class)->embedPending())->toBe(2);

    expect($chunk->fresh()->embedding)->toBe(array_fill(0, 1024, 0.5));
    expect(KbChunk::withoutGlobalScopes()->find($otherChunk->id)->embedding)->toBe(array_fill(0, 1024, 0.5));
    expect($embedded->fresh()->embedding)->toBe(array_fill(0, 1024, 0.25));
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['tenant_id'] === $tenant->id && $request['chunk_ids'] === [$chunk->id]);
});
