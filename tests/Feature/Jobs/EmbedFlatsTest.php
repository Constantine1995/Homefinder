<?php

use App\Jobs\EmbedFlats;
use App\Models\Flat;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('leaves a warning instead of failing when the agent service is down', function () {
    Http::preventStrayRequests();
    Http::fake(['agent.invalid/v1/flats/embeddings' => Http::failedConnection()]);
    Log::spy();
    Flat::factory()->create();

    EmbedFlats::dispatchSync();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => $message === 'The flats were not embedded after the import.');
});
