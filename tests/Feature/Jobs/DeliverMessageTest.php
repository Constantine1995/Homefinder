<?php

use App\Events\WidgetMessageSent;
use App\Jobs\DeliverMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\Telegram\TelegramMessenger;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('leaves a message undelivered without retrying when the buyer blocked the bot', function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $message = Message::factory()->for(Conversation::factory()->withContact(Contact::factory()->withPhone()->create()))->fromAgent()->create();
    $job = (new DeliverMessage($message))->withFakeQueueInteractions();

    $job->handle(app(TelegramMessenger::class));

    $job->assertNotReleased()->assertNotFailed();
    expect($message->fresh()->delivered_at)->toBeNull();
});

it('retries when Telegram asks to wait', function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 17]], 429)]);
    $message = Message::factory()->for(Conversation::factory()->withContact(Contact::factory()->withPhone()->create()))->fromAgent()->create();
    $job = (new DeliverMessage($message))->withFakeQueueInteractions();

    $job->handle(app(TelegramMessenger::class));

    $job->assertReleased(delay: 17);
});

it('sends nothing for a message of the test chat', function () {
    Http::preventStrayRequests();
    $message = Message::factory()->fromAgent()->create();

    (new DeliverMessage($message))->handle(app(TelegramMessenger::class));

    Http::assertNothingSent();
});

it('tells the visitor\'s open widget about a message instead of sending it anywhere', function () {
    Http::preventStrayRequests();
    Event::fake([WidgetMessageSent::class]);
    $visitor = Contact::factory()->for(Channel::factory()->widget())->create();
    $message = Message::factory()->for(Conversation::factory()->withContact($visitor))->fromAgent()->create();

    (new DeliverMessage($message))->handle(app(TelegramMessenger::class));

    Event::assertDispatched(WidgetMessageSent::class, fn (WidgetMessageSent $event): bool => $event->contactId === $visitor->id
        && $event->messageId === $message->id);
    Http::assertNothingSent();
    expect($message->fresh()->delivered_at)->toBeNull();
});
