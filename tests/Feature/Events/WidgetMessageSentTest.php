<?php

use App\Events\ConversationUpdated;
use App\Events\WidgetMessageSent;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    // The channels were registered with the test suite's null broadcaster, which authorizes nothing.
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
});

it('broadcasts only the IDs, on the visitor\'s private channel', function () {
    $event = new WidgetMessageSent(7, 42);

    expect($event->broadcastOn()[0]->name)->toBe('private-widget.contacts.7')
        ->and($event->broadcastAs())->toBe('message.sent')
        ->and($event->broadcastWith())->toBe(['message_id' => 42]);
});

it('lets the visitor listen to its own channel only, by its token', function () {
    $channel = Channel::factory()->widget()->create();
    $token = Contact::startWidgetVisitor($channel);
    $visitor = Contact::sole();
    $other = Contact::factory()->for($channel)->create();

    $this->withToken($token)->postJson('/api/v1/widget/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-widget.contacts.'.$visitor->id])
        ->assertOk()
        ->assertJsonStructure(['auth']);
    $this->withToken($token)->postJson('/api/v1/widget/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-widget.contacts.'.$other->id])
        ->assertForbidden();
    $this->withToken($token)->postJson('/api/v1/widget/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.ConversationUpdated::channelName($visitor->tenant_id)])
        ->assertForbidden();
});

it('refuses to authorize a visitor\'s channel without the token', function () {
    $visitor = Contact::factory()->for(Channel::factory()->widget())->create();

    $this->postJson('/api/v1/widget/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-widget.contacts.'.$visitor->id])
        ->assertUnauthorized();
});

it('keeps employees out of the visitors\' channels', function () {
    $tenant = Tenant::factory()->create();
    $visitor = Contact::factory()->for(Channel::factory()->for($tenant)->widget())->create();
    $this->actingAs(User::factory()->for($tenant)->create());

    $this->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-widget.contacts.'.$visitor->id])
        ->assertForbidden();
});
