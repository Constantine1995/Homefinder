<?php

use App\Jobs\HandleTelegramUpdate;
use App\Models\Channel;
use Illuminate\Support\Facades\Queue;

it('queues an update that comes with the bot\'s secret', function () {
    Queue::fake([HandleTelegramUpdate::class]);
    $channel = Channel::factory()->create();
    $update = telegramMessage(updateId: 15);

    $response = $this->postJson("/webhooks/telegram/{$channel->id}", $update, ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret']);

    $response->assertOk();
    Queue::assertPushed(HandleTelegramUpdate::class, fn (HandleTelegramUpdate $job): bool => $job->channel->is($channel) && $job->update === $update);
});

it('answers 404 to a call without the bot\'s secret', function (?string $secret) {
    Queue::fake([HandleTelegramUpdate::class]);
    $channel = Channel::factory()->create();

    $response = $this->postJson("/webhooks/telegram/{$channel->id}", telegramMessage(), array_filter(['X-Telegram-Bot-Api-Secret-Token' => $secret]));

    $response->assertNotFound();
    Queue::assertNotPushed(HandleTelegramUpdate::class);
})->with(['no secret' => [null], 'another bot\'s secret' => ['other-secret']]);

it('answers 404 to a body that is not an update', function () {
    Queue::fake([HandleTelegramUpdate::class]);
    $channel = Channel::factory()->create();

    $response = $this->postJson("/webhooks/telegram/{$channel->id}", ['hello' => 'world'], ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret']);

    $response->assertNotFound();
    Queue::assertNotPushed(HandleTelegramUpdate::class);
});

it('answers 404 for a channel that does not exist', function () {
    $this->postJson('/webhooks/telegram/999', telegramMessage(), ['X-Telegram-Bot-Api-Secret-Token' => 'test-webhook-secret'])
        ->assertNotFound();
});
