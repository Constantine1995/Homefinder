<?php

use App\Models\Channel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('points the bot\'s webhook at this application with the channel\'s secret', function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*/setWebhook' => Http::response(['ok' => true, 'result' => true])]);
    $channel = Channel::factory()->create();

    $this->artisan('telegram:webhook', ['channel' => $channel->id])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'url' => route('webhooks.telegram', $channel),
        'secret_token' => 'test-webhook-secret',
        'allowed_updates' => ['message', 'callback_query'],
    ]);
});

it('stops the webhook when asked', function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*/deleteWebhook' => Http::response(['ok' => true, 'result' => true])]);
    $channel = Channel::factory()->create();

    $this->artisan('telegram:webhook', ['channel' => $channel->id, '--delete' => true])->assertSuccessful();

    Http::assertSentCount(1);
});
