<?php

use App\Models\Flat;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('reports how many flats got a vector', function () {
    $flat = Flat::factory()->create();
    Http::fake(['agent.invalid/v1/flats/embeddings' => Http::response([
        'model' => 'ollama/bge-m3',
        'flats' => [['id' => $flat->id, 'embedding' => array_fill(0, 1024, 0.5)]],
    ])]);

    $this->artisan('search:embed')
        ->expectsOutputToContain('Flats embedded: 1. Knowledge chunks embedded: 0.')
        ->assertSuccessful();
});

it('fails when the agent service is down', function () {
    Flat::factory()->create();
    Http::fake(['agent.invalid/v1/flats/embeddings' => Http::failedConnection()]);

    $this->artisan('search:embed')
        ->expectsOutputToContain('Embedding stopped: The agent service could not be reached')
        ->assertFailed();
});
