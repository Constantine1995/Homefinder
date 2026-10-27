<?php

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function fakeGetMe(int $botId = 7_000_000_001): void
{
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/bot7000000001:AAE-token/getMe' => Http::response(['ok' => true, 'result' => [
        'id' => $botId, 'is_bot' => true, 'first_name' => 'Демо-застройщик', 'username' => 'homefinder_demo_bot',
    ]])]);
}

it('connects the bot as the developer\'s channel with the token kept encrypted', function () {
    fakeGetMe();
    $tenant = Tenant::factory()->create(['name' => 'Демо-застройщик']);

    $this->artisan('telegram:connect', ['token' => '7000000001:AAE-token', '--tenant' => $tenant->id])
        ->expectsOutputToContain('The bot @homefinder_demo_bot is channel')
        ->assertSuccessful();

    $channel = Channel::withoutGlobalScopes()->sole();
    expect($channel->tenant_id)->toBe($tenant->id)
        ->and($channel->type)->toBe(ChannelType::Telegram)
        ->and($channel->name)->toBe('@homefinder_demo_bot')
        ->and($channel->external_id)->toBe('7000000001')
        ->and($channel->token())->toBe('7000000001:AAE-token')
        ->and(strlen($channel->webhookSecret()))->toBe(48)
        ->and($channel->policyUrl())->toBe(route('demo.privacy'))
        ->and(DB::table('channels')->value('credentials'))->not->toContain('AAE-token');
});

it('keeps the webhook secret when the bot is connected again with a new policy', function () {
    fakeGetMe();
    $channel = Channel::factory()->create(['external_id' => '7000000001']);

    $this->artisan('telegram:connect', ['token' => '7000000001:AAE-token', '--tenant' => $channel->tenant_id, '--policy-url' => 'https://developer.test/policy'])
        ->assertSuccessful();

    $channel->refresh();
    expect(Channel::withoutGlobalScopes()->count())->toBe(1)
        ->and($channel->token())->toBe('7000000001:AAE-token')
        ->and($channel->webhookSecret())->toBe('test-webhook-secret')
        ->and($channel->policyUrl())->toBe('https://developer.test/policy');
});

it('refuses a bot connected for another developer', function () {
    fakeGetMe();
    $channel = Channel::factory()->create(['external_id' => '7000000001']);
    $tenant = Tenant::factory()->create();

    $this->artisan('telegram:connect', ['token' => '7000000001:AAE-token', '--tenant' => $tenant->id])
        ->expectsOutputToContain('connected for another developer')
        ->assertFailed();

    expect($channel->fresh()->tenant_id)->not->toBe($tenant->id);
});

it('refuses a token Telegram does not know', function () {
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);
    $tenant = Tenant::factory()->create();

    $this->artisan('telegram:connect', ['token' => '1:wrong', '--tenant' => $tenant->id])
        ->expectsOutputToContain('Unauthorized')
        ->assertFailed();

    expect(Channel::withoutGlobalScopes()->count())->toBe(0);
});
