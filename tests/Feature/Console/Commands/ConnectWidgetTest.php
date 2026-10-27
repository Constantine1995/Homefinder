<?php

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Tenant;

it('connects the widget for the developer and prints the tag to embed', function () {
    $tenant = Tenant::factory()->create(['name' => 'Демо-застройщик']);

    $this->artisan('widget:connect', ['--tenant' => $tenant->id])
        ->expectsOutputToContain('The widget is channel')
        ->expectsOutputToContain('/widget/widget.js" data-key="')
        ->assertSuccessful();

    $channel = Channel::withoutGlobalScopes()->sole();
    expect($channel->tenant_id)->toBe($tenant->id)
        ->and($channel->type)->toBe(ChannelType::Widget)
        ->and($channel->external_id)->toHaveLength(32)
        ->and($channel->is_active)->toBeTrue()
        ->and($channel->policyUrl())->toBe(route('demo.privacy'));
});

it('keeps the key the sites embed when the widget is connected again with a new policy', function () {
    $channel = Channel::factory()->widget()->inactive()->create();

    $this->artisan('widget:connect', ['--tenant' => $channel->tenant_id, '--policy-url' => 'https://developer.test/policy'])
        ->expectsOutputToContain("data-key=\"{$channel->external_id}\"")
        ->assertSuccessful();

    $connected = Channel::withoutGlobalScopes()->sole();
    expect($connected->external_id)->toBe($channel->external_id)
        ->and($connected->is_active)->toBeTrue()
        ->and($connected->policyUrl())->toBe('https://developer.test/policy');
});

it('refuses a developer that does not exist', function () {
    $this->artisan('widget:connect', ['--tenant' => 999])->assertFailed();

    expect(Channel::withoutGlobalScopes()->count())->toBe(0);
});
