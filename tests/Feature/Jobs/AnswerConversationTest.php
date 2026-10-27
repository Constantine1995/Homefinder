<?php

use App\Jobs\AnswerConversation;
use App\Jobs\DeliverMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Dialogs\ConversationResponder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('sends the agent\'s reply to the buyer\'s channel', function () {
    Http::preventStrayRequests();
    Http::fake(['agent.invalid/v1/chat' => Http::response(['detail' => 'The language model is unavailable'], 503)]);
    Queue::fake([DeliverMessage::class]);
    $conversation = Conversation::factory()->withContact()->create();
    Message::factory()->for($conversation)->create();

    (new AnswerConversation($conversation))->handle(app(ConversationResponder::class));

    $reply = $conversation->messages()->latest('id')->first();
    Queue::assertPushed(DeliverMessage::class, fn (DeliverMessage $job): bool => $job->message->is($reply));
});

it('sends nothing anywhere from the test chat', function () {
    Http::preventStrayRequests();
    Http::fake(['agent.invalid/v1/chat' => Http::response(['detail' => 'The language model is unavailable'], 503)]);
    Queue::fake([DeliverMessage::class]);
    $conversation = Conversation::factory()->create();
    Message::factory()->for($conversation)->create();

    (new AnswerConversation($conversation))->handle(app(ConversationResponder::class));

    Queue::assertNotPushed(DeliverMessage::class);
});

it('gives a turn longer than the agent service may take', function () {
    config(['services.agent.timeout' => 240]);

    expect((new AnswerConversation(Conversation::factory()->create()))->timeout)->toBe(270);
});
