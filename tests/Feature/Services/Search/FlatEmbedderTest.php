<?php

use App\Models\Flat;
use App\Models\Tenant;
use App\Services\Agent\AgentException;
use App\Services\Search\FlatEmbedder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * A 1024-dimensional vector whose components are all the given value; eighths survive float4 exactly.
 *
 * @return list<float>
 */
function flatVector(float $value): array
{
    return array_fill(0, 1024, $value);
}

/**
 * Make the agent service answer each request for vectors with a vector of the flat ID in eighths, and
 * remember the requests.
 *
 * @param  list<int>  $skippedFlatIds  Flats the service has nothing to embed for.
 * @return ArrayObject<int, array<string, mixed>>
 */
function agentEmbedding(array $skippedFlatIds = []): ArrayObject
{
    $requests = new ArrayObject;

    Http::fake(['agent.invalid/v1/flats/embeddings' => function (Request $request) use ($requests, $skippedFlatIds) {
        $requests[] = $request->data();

        return Http::response(['model' => 'ollama/bge-m3', 'flats' => collect($request['flat_ids'])
            ->diff($skippedFlatIds)
            ->map(fn (int $id): array => ['id' => $id, 'embedding' => flatVector(($id % 8) / 8)])
            ->values()
            ->all()]);
    }]);

    return $requests;
}

it('stores the vectors of the flats without one, asking the agent service tenant by tenant', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $flat = Flat::factory()->recycle($tenant)->create();
    $otherFlat = Flat::factory()->recycle($otherTenant)->create();
    $embeddedFlat = Flat::factory()->recycle($tenant)->create(['embedding' => flatVector(0.5)]);
    $requests = agentEmbedding();

    $embeddedCount = app(FlatEmbedder::class)->embedPending();

    expect($embeddedCount)->toBe(2);
    expect($flat->fresh()->embedding)->toBe(flatVector(($flat->id % 8) / 8));
    expect($otherFlat->fresh()->embedding)->toBe(flatVector(($otherFlat->id % 8) / 8));
    expect($embeddedFlat->fresh()->embedding)->toBe(flatVector(0.5));
    expect($requests->getArrayCopy())->toEqualCanonicalizing([
        ['tenant_id' => $tenant->id, 'flat_ids' => [$flat->id]],
        ['tenant_id' => $otherTenant->id, 'flat_ids' => [$otherFlat->id]],
    ]);
});

it('embeds the flats of the current tenant only when there is one', function () {
    $tenant = Tenant::factory()->create();
    $flat = Flat::factory()->recycle($tenant)->create();
    $otherFlat = Flat::factory()->create();
    agentEmbedding();

    $tenant->run(fn () => app(FlatEmbedder::class)->embedPending());

    expect($flat->fresh()->embedding)->not->toBeNull();
    expect(Flat::withoutGlobalScopes()->find($otherFlat->id)->embedding)->toBeNull();
});

it('sends the flats in batches', function () {
    $tenant = Tenant::factory()->create();
    Flat::factory()->count(FlatEmbedder::BATCH_SIZE + 1)->recycle($tenant)->create();
    $requests = agentEmbedding();

    app(FlatEmbedder::class)->embedPending();

    expect(array_map(fn (array $request): int => count($request['flat_ids']), $requests->getArrayCopy()))
        ->toBe([FlatEmbedder::BATCH_SIZE, 1]);
});

it('leaves the flats the agent service had nothing to embed for', function () {
    $flat = Flat::factory()->create();
    $bareFlat = Flat::factory()->create();
    agentEmbedding(skippedFlatIds: [$bareFlat->id]);

    expect(app(FlatEmbedder::class)->embedPending())->toBe(1);
    expect($flat->fresh()->embedding)->not->toBeNull();
    expect($bareFlat->fresh()->embedding)->toBeNull();
});

it('keeps the time a flat was last changed', function () {
    $flat = Flat::factory()->create(['updated_at' => '2026-10-01 09:00:00']);
    agentEmbedding();

    app(FlatEmbedder::class)->embedPending();

    expect($flat->fresh()->updated_at->toDateTimeString())->toBe('2026-10-01 09:00:00');
});

it('drops the vector of a flat whose description changed while the vector was being computed', function () {
    $flat = Flat::factory()->create(['description' => 'Окна во двор.', 'updated_at' => '2026-10-01 09:00:00']);
    Http::fake(['agent.invalid/v1/flats/embeddings' => function () use ($flat) {
        Flat::whereKey($flat->id)->update(['description' => 'Окна на парк.', 'updated_at' => '2026-10-01 09:05:00']);

        return Http::response(['model' => 'ollama/bge-m3', 'flats' => [['id' => $flat->id, 'embedding' => flatVector(0.5)]]]);
    }]);

    expect(app(FlatEmbedder::class)->embedPending())->toBe(0);
    expect($flat->fresh()->embedding)->toBeNull();
});

it('waits for the next run while another run is going', function () {
    Flat::factory()->create();
    Cache::lock('search-embed', 60)->get();

    expect(app(FlatEmbedder::class)->embedPending())->toBeNull();
    Http::assertNothingSent();
});

it('stops when the agent service fails and lets the next run go', function () {
    Flat::factory()->create();
    Http::fake(['agent.invalid/v1/flats/embeddings' => Http::response(['detail' => 'The language model is unavailable'], 503)]);

    expect(fn () => app(FlatEmbedder::class)->embedPending())
        ->toThrow(AgentException::class, 'HTTP 503');
    expect(Cache::lock('search-embed', 60)->get())->toBeTrue();
});

it('sends the service token', function () {
    config(['services.agent.token' => 'secret-service-token']);
    Flat::factory()->create();
    agentEmbedding();

    app(FlatEmbedder::class)->embedPending();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-service-token'));
});
