<?php

use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

it('tells the developer\'s cabinets about a new message', function () {
    $conversation = Conversation::factory()->create();
    Event::fake([ConversationUpdated::class]);

    Message::factory()->for($conversation)->create();

    Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event): bool => $event->tenantId === $conversation->tenant_id
        && $event->conversationId === $conversation->id);
});

it('tells the developer\'s cabinets who answers the buyer now', function () {
    $conversation = Conversation::factory()->create();
    $manager = User::factory()->for($conversation->tenant)->create();
    Event::fake([ConversationUpdated::class]);

    $conversation->takeBy($manager);

    Event::assertDispatchedTimes(ConversationUpdated::class, 1);
});

it('tells the developer\'s cabinets when the lead card changes', function () {
    $lead = Lead::factory()->create();
    Event::fake([ConversationUpdated::class]);

    $lead->update(['budget' => 12_000_000]);
    $lead->save();

    Event::assertDispatchedTimes(ConversationUpdated::class, 1);
});

it('keeps quiet about changes the shared chat does not show', function () {
    $conversation = Conversation::factory()->create();
    Event::fake([ConversationUpdated::class]);

    $conversation->update(['dialog' => ['filters' => ['rooms' => [2]]], 'last_message_at' => now()]);

    Event::assertNotDispatched(ConversationUpdated::class);
});

it('broadcasts only the IDs, on the developer\'s private channel', function () {
    $event = new ConversationUpdated(7, 42);

    expect($event->broadcastOn()[0]->name)->toBe('private-tenants.7.conversations')
        ->and($event->broadcastAs())->toBe('conversation.updated')
        ->and($event->broadcastWith())->toBe(['conversation_id' => 42]);
});

it('lets only the developer\'s employees listen to its conversations', function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    // The channels were registered with the test suite's null broadcaster, which authorizes nothing.
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
    $tenant = Tenant::factory()->create();
    $this->actingAs(User::factory()->for($tenant)->create());
    $channel = 'private-'.ConversationUpdated::channelName($tenant->id);

    $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
        ->assertOk()
        ->assertJsonStructure(['auth']);
    $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.ConversationUpdated::channelName($tenant->id + 1)])
        ->assertForbidden();
});
