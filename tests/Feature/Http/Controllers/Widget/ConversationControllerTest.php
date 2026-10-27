<?php

use App\Enums\FlatStatus;
use App\Enums\LeadScore;
use App\Enums\MessageRole;
use App\Jobs\AnswerConversation;
use App\Models\AgentRun;
use App\Models\Building;
use App\Models\Channel;
use App\Models\Complex;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flat;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Models\Tenant;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Queue;

/**
 * A visitor of a widget of the developer as the widget starts one, with the token it would keep.
 *
 * @return array{0: Contact, 1: string}
 */
function widgetVisitor(?Tenant $tenant = null, bool $consented = true): array
{
    $channel = Channel::factory()->for($tenant ?? Tenant::factory())->widget()->create();
    $token = Contact::startWidgetVisitor($channel);
    $contact = Contact::sole();

    if ($consented) {
        $contact->update(['consent_at' => now()]);
    }

    return [$contact, $token];
}

function widgetFlat(Tenant $tenant, array $attributes = []): Flat
{
    $complex = Complex::factory()->for($tenant)->create(['name' => 'Тёплые холмы']);

    return Flat::factory()->for(Building::factory()->for($complex))->create(['number' => '52', ...$attributes]);
}

/**
 * The agent's reply to the visitor, written by a turn whose search showed the given flats.
 *
 * @param  list<Flat>  $flats
 */
function widgetAgentReply(Conversation $conversation, array $flats = [], string $text = 'Подобрал **две** квартиры.'): Message
{
    $message = Message::factory()->for($conversation)->fromAgent($text)->create();
    AgentRun::factory()->for($conversation)->create([
        'message_id' => $message->id,
        'steps' => [['tool' => 'search_flats', 'args' => [], 'summary' => '', 'data' => [], 'flat_ids' => array_map(fn (Flat $flat): int => $flat->id, $flats)]],
    ]);

    return $message;
}

it('refuses requests without the visitor\'s token, with an unknown one, or of a switched-off widget', function (Closure $token) {
    $this->withToken($token())->getJson('/api/v1/widget/conversation')->assertUnauthorized();
    $this->postJson('/api/v1/widget/messages', ['text' => 'двушка'])->assertUnauthorized();

    expect(Message::count())->toBe(0);
})->with([
    'no token' => fn (): string => '',
    'an unknown token' => fn (): string => str_repeat('a', 48),
    'a switched-off widget' => function (): string {
        [$visitor, $token] = widgetVisitor();
        $visitor->channel->update(['is_active' => false]);

        return $token;
    },
]);

it('shows the visitor\'s dialog with the cards of the flats still on sale and marks the replies delivered', function () {
    [$visitor, $token] = widgetVisitor();
    $earlier = Conversation::factory()->withContact($visitor)->closed()->create();
    Message::factory()->for($earlier)->create(['text' => 'есть ли паркинг?']);
    $conversation = Conversation::factory()->withContact($visitor)->create();
    Message::factory()->for($conversation)->create(['text' => 'двушка до 9 млн']);
    $onSale = widgetFlat($visitor->tenant, ['rooms' => 2, 'is_euro' => true, 'area' => 34.8, 'price' => 8_249_000, 'status' => FlatStatus::Reserved, 'plan_url' => 'http://localhost:8080/demo/plans/2E-35.svg']);
    $sold = widgetFlat($visitor->tenant, ['status' => FlatStatus::Sold]);
    $reply = widgetAgentReply($conversation, [$sold, $onSale]);

    $response = $this->withToken($token)->getJson('/api/v1/widget/conversation');

    $response->assertOk()
        ->assertJsonPath('channel', "widget.contacts.{$visitor->id}")
        ->assertJsonPath('consented', true)
        ->assertJsonPath('status', 'ai')
        ->assertJsonPath('waiting', false)
        ->assertJsonPath('phone_requested', false)
        ->assertJsonPath('messages.*.text', ['есть ли паркинг?', 'двушка до 9 млн', 'Подобрал **две** квартиры.'])
        ->assertJsonPath('messages.0.flats', [])
        ->assertJsonPath('messages.2.flats', [[
            'id' => $onSale->id,
            'title' => '2Е · 34,8 м² · 8 249 000 ₽',
            'place' => "ЖК «Тёплые холмы», {$onSale->building->name}, кв. 52",
            'details' => collect([
                "Этаж {$onSale->floor}".($onSale->building->floors === null ? '' : " из {$onSale->building->floors}"),
                $onSale->finishing->label(),
                $onSale->building->deadline === null ? null : 'сдача '.['I', 'II', 'III', 'IV'][intdiv($onSale->building->deadline->month - 1, 3)].' кв. '.$onSale->building->deadline->year,
            ])->filter()->implode(' · '),
            'plan_url' => 'http://localhost:8080/demo/plans/2E-35.svg',
            'reserved' => true,
        ]]);
    expect($reply->fresh()->delivered_at)->not->toBeNull()
        ->and(Message::where('role', MessageRole::Buyer)->whereNotNull('delivered_at')->exists())->toBeFalse();
});

it('waits while the buyer\'s latest message has no reply and asks for a phone once a manager has the dialog', function () {
    [$visitor, $token] = widgetVisitor();
    $conversation = Conversation::factory()->withContact($visitor)->create();
    Message::factory()->for($conversation)->create();

    $this->withToken($token)->getJson('/api/v1/widget/conversation')->assertJsonPath('waiting', true);

    $conversation->handOff('покупатель просит менеджера');

    $this->withToken($token)->getJson('/api/v1/widget/conversation')
        ->assertJsonPath('waiting', false)
        ->assertJsonPath('status', 'human')
        ->assertJsonPath('phone_requested', true);
});

it('shows only the visitor\'s own dialog', function () {
    [$visitor, $token] = widgetVisitor();
    Message::factory()->for(Conversation::factory()->withContact(Contact::factory()->for($visitor->channel)->create()))->create();

    $this->withToken($token)->getJson('/api/v1/widget/conversation')->assertJsonPath('messages', []);
});

it('takes the visitor\'s consent', function () {
    [$visitor, $token] = widgetVisitor(consented: false);

    $this->withToken($token)->postJson('/api/v1/widget/consent')->assertOk()->assertJsonPath('consented', true);

    expect($visitor->fresh()->hasConsented())->toBeTrue();
});

it('lets the visitor write only after the consent', function () {
    Queue::fake([AnswerConversation::class]);
    [, $token] = widgetVisitor(consented: false);

    $this->withToken($token)->postJson('/api/v1/widget/messages', ['text' => 'двушка'])
        ->assertConflict()
        ->assertJsonPath('message', 'Чтобы продолжить, подтвердите согласие на обработку персональных данных.');

    expect(Message::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('stores the visitor\'s message in a widget conversation and queues the agent\'s answer', function () {
    Queue::fake([AnswerConversation::class]);
    [$visitor, $token] = widgetVisitor();

    $response = $this->withToken($token)->postJson('/api/v1/widget/messages', ['text' => '  двушка до 9 млн ']);

    $response->assertCreated()->assertJsonPath('waiting', true)->assertJsonPath('messages.0.text', 'двушка до 9 млн');
    $conversation = $visitor->conversations()->sole();
    expect($conversation->channel->value)->toBe('widget')
        ->and($conversation->tenant_id)->toBe($visitor->tenant_id);
    Queue::assertPushedOn('agent', AnswerConversation::class, fn (AnswerConversation $job): bool => $job->conversation->is($conversation));
});

it('leaves the message to the manager who has the dialog', function () {
    Queue::fake([AnswerConversation::class]);
    [$visitor, $token] = widgetVisitor();
    $conversation = Conversation::factory()->withContact($visitor)->handedOff()->create();

    $this->withToken($token)->postJson('/api/v1/widget/messages', ['text' => 'алло?'])->assertCreated();

    expect($conversation->messages()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('rejects an empty message', function () {
    [, $token] = widgetVisitor();

    $this->withToken($token)->postJson('/api/v1/widget/messages', ['text' => '  '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['text' => 'The text field is required.']);
});

it('limits the messages a visitor may send in an hour', function () {
    Queue::fake([AnswerConversation::class]);
    [, $token] = widgetVisitor();

    foreach (range(1, AppServiceProvider::WIDGET_MESSAGES_PER_HOUR) as $attempt) {
        $this->withToken($token)->postJson('/api/v1/widget/messages', ['text' => "вопрос {$attempt}"])->assertCreated();
    }

    $this->withToken($token)->postJson('/api/v1/widget/messages', ['text' => 'ещё'])
        ->assertTooManyRequests()
        ->assertJsonPath('message', AppServiceProvider::TOO_MANY_MESSAGES);
    expect(Message::count())->toBe(AppServiceProvider::WIDGET_MESSAGES_PER_HOUR);
});

it('writes the request of a flat card\'s button as the visitor\'s message', function (string $request, string $text) {
    Queue::fake([AnswerConversation::class]);
    [$visitor, $token] = widgetVisitor();
    $flat = widgetFlat($visitor->tenant, ['rooms' => 2, 'is_euro' => true, 'price' => 8_459_000, 'features' => []]);

    $this->withToken($token)->postJson("/api/v1/widget/flats/{$flat->id}/{$request}")->assertCreated();

    expect(Message::sole()->text)->toBe($text);
    Queue::assertPushed(AnswerConversation::class);
})->with([
    'booking' => ['booking', 'Хочу записаться на просмотр: кв. 52 в ЖК «Тёплые холмы»'],
    'similar' => ['similar', 'Покажите похожие на кв. 52 в ЖК «Тёплые холмы»: евродвушка до 9,4 млн ₽'],
]);

it('tells the visitor a sold flat is gone and hides another developer\'s flat', function () {
    [$visitor, $token] = widgetVisitor();
    $sold = widgetFlat($visitor->tenant, ['status' => FlatStatus::Sold]);
    $foreign = widgetFlat(Tenant::factory()->create());

    $this->withToken($token)->postJson("/api/v1/widget/flats/{$sold->id}/booking")
        ->assertNotFound()
        ->assertJsonPath('message', 'Эта квартира уже не продаётся.');
    $this->withToken($token)->postJson("/api/v1/widget/flats/{$foreign->id}/booking")->assertNotFound();

    expect(Message::count())->toBe(0);
});

it('keeps the phone the visitor left for the manager and warms the lead up', function (string $written, string $kept) {
    [$visitor, $token] = widgetVisitor();
    $conversation = Conversation::factory()->withContact($visitor)->handedOff()->create();
    $lead = Lead::factory()->for($conversation)->create(['contact_id' => $visitor->id, 'budget' => 9_000_000, 'rooms' => [2], 'payment' => 'mortgage']);
    expect($lead->score)->toBe(LeadScore::Warm);

    $this->withToken($token)->postJson('/api/v1/widget/phone', ['phone' => $written])
        ->assertOk()
        ->assertJsonPath('phone_requested', false);

    expect($visitor->fresh()->phone)->toBe($kept)
        ->and($lead->fresh()->score)->toBe(LeadScore::Hot);
})->with([
    'with 8' => ['8 (916) 123-45-67', '+79161234567'],
    'with +7' => ['+7 916 123-45-67', '+79161234567'],
    'without the country code' => ['916 123 45 67', '+79161234567'],
]);

it('rejects a phone with a mistake', function (string $phone) {
    [$visitor, $token] = widgetVisitor();

    $this->withToken($token)->postJson('/api/v1/widget/phone', ['phone' => $phone])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone' => 'Похоже, в номере ошибка — напишите его, например, как +7 916 123-45-67.']);

    expect($visitor->fresh()->phone)->toBeNull();
})->with(['letters' => 'позвоните мне', 'too short' => '123-45-67', 'too long' => '+7 916 123 45 67 89 01']);

it('ends the visitor\'s own subscription and hides another buyer\'s', function () {
    [$visitor, $token] = widgetVisitor();
    $own = SavedSearch::factory()->for(Conversation::factory()->withContact($visitor))->create();
    $foreign = SavedSearch::factory()->for(Conversation::factory()->withContact(Contact::factory()->for($visitor->channel)->create()))->create();

    $this->withToken($token)->deleteJson("/api/v1/widget/saved-searches/{$foreign->id}")->assertNotFound();
    $this->withToken($token)->deleteJson("/api/v1/widget/saved-searches/{$own->id}")->assertOk();

    expect($own->fresh()->active)->toBeFalse()
        ->and($foreign->fresh()->active)->toBeTrue();
});
