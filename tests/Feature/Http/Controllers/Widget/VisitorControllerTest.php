<?php

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tenant;
use App\Providers\AppServiceProvider;

it('tells the widget what to say before the visitor agrees to the processing of data', function () {
    $tenant = Tenant::factory()->create(['name' => 'СК «Горизонт»']);
    $channel = Channel::factory()->for($tenant)->widget()->create(['settings' => ['policy_url' => 'https://gorizont.test/privacy']]);

    $response = $this->getJson("/api/v1/widget/channels/{$channel->external_id}");

    $response->assertOk()->assertExactJson([
        'name' => 'СК «Горизонт»',
        'introduction' => 'Здравствуйте! Я ИИ-помощник отдела продаж «СК «Горизонт»»: подберу квартиру, посчитаю ипотеку, '
            .'отвечу на вопросы о ЖК и помогу записаться на просмотр.',
        'policy_url' => 'https://gorizont.test/privacy',
    ]);
});

it('starts a visitor of the developer and keeps only the hash of its token', function () {
    $channel = Channel::factory()->widget()->create();

    $response = $this->postJson("/api/v1/widget/channels/{$channel->external_id}/visitors");

    $response->assertCreated();
    $token = $response->json('token');
    $contact = Contact::sole();
    expect($token)->toBeString()->toHaveLength(48)
        ->and($contact->channel_id)->toBe($channel->id)
        ->and($contact->tenant_id)->toBe($channel->tenant_id)
        ->and($contact->channel_user_id)->toBe(hash('sha256', $token))
        ->and($contact->hasConsented())->toBeFalse();
});

it('looks like no widget for a switched-off widget or another kind of channel', function (Channel $channel) {
    $this->getJson("/api/v1/widget/channels/{$channel->external_id}")->assertNotFound();
    $this->postJson("/api/v1/widget/channels/{$channel->external_id}/visitors")->assertNotFound();

    expect(Contact::count())->toBe(0);
})->with([
    'a switched-off widget' => fn () => Channel::factory()->widget()->inactive()->create(),
    'a Telegram bot' => fn () => Channel::factory()->create(['type' => ChannelType::Telegram]),
]);

it('limits the visitors one address may start in an hour', function () {
    $channel = Channel::factory()->widget()->create();

    foreach (range(1, AppServiceProvider::WIDGET_VISITORS_PER_HOUR) as $attempt) {
        $this->postJson("/api/v1/widget/channels/{$channel->external_id}/visitors")->assertCreated();
    }

    $this->postJson("/api/v1/widget/channels/{$channel->external_id}/visitors")->assertTooManyRequests();
    expect(Contact::count())->toBe(AppServiceProvider::WIDGET_VISITORS_PER_HOUR);
});
