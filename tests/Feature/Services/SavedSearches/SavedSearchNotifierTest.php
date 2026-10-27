<?php

use App\Enums\ConversationStatus;
use App\Enums\FlatEventField;
use App\Enums\FlatStatus;
use App\Enums\MessageRole;
use App\Enums\PoiType;
use App\Jobs\DeliverMessage;
use App\Models\Building;
use App\Models\Channel;
use App\Models\Complex;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flat;
use App\Models\FlatEvent;
use App\Models\Message;
use App\Models\Poi;
use App\Models\SavedSearch;
use App\Models\Tenant;
use App\Services\SavedSearches\SavedSearchNotifier;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo('2026-10-25 12:00:00');
    $this->tenant = Tenant::factory()->create();
    $this->complex = Complex::factory()->for($this->tenant)->create(['name' => 'Тёплые холмы']);
    $this->building = Building::factory()->for($this->complex)->create();
});

/**
 * A flat of the developer that went on sale at the given moment.
 *
 * @param  array<string, mixed>  $attributes
 */
function flatOnSale(Building $building, array $attributes = [], string $at = '2026-10-25 10:00:00'): Flat
{
    $flat = Flat::factory()->for($building)->create(['rooms' => 2, 'is_euro' => false, 'area' => 54.3, 'status' => FlatStatus::Available, ...$attributes]);
    FlatEvent::factory()->for($flat)->create(['field' => FlatEventField::Status, 'old_value' => null, 'new_value' => $flat->status->value, 'created_at' => $at]);

    return $flat;
}

function priceChange(Flat $flat, int $from, string $at = '2026-10-25 10:00:00'): void
{
    FlatEvent::factory()->for($flat)->create(['field' => FlatEventField::Price, 'old_value' => $from, 'new_value' => $flat->price, 'created_at' => $at]);
}

/**
 * A Telegram buyer's subscription to two-room flats up to 9 million, made the day before.
 *
 * @param  array<string, mixed>  $attributes
 */
function subscription(Tenant $tenant, array $attributes = []): SavedSearch
{
    $contact = Contact::factory()->for(Channel::factory()->for($tenant))->create();
    $conversation = Conversation::factory()->withContact($contact)->closed()->create();

    return SavedSearch::factory()->for($conversation)->create(['created_at' => '2026-10-24 12:00:00', ...$attributes]);
}

function notifySubscribers(Tenant $tenant): int
{
    return $tenant->run(fn (): int => app(SavedSearchNotifier::class)->notify());
}

it('tells the buyer about new and cheaper flats that fit, the cheapest first, in a new conversation for the buyer', function () {
    $search = subscription($this->tenant);
    $cheaper = Flat::factory()->for($this->building)->create(['number' => '12', 'rooms' => 2, 'is_euro' => true, 'area' => 34.8, 'price' => 8_460_000]);
    priceChange($cheaper, from: 8_700_000);
    $new = flatOnSale($this->building, ['number' => '40', 'price' => 8_900_000]);

    expect(notifySubscribers($this->tenant))->toBe(1);

    $message = Message::withoutGlobalScopes()->sole();
    $conversation = $message->conversation;
    expect($message->role)->toBe(MessageRole::Agent)
        ->and($message->saved_search_id)->toBe($search->id)
        ->and($message->text)->toBe(
            "По вашей подписке «2-комн., до 9 млн ₽» появились квартиры:\n"
            ."— кв. 12 в ЖК «Тёплые холмы»: 2Е, 34,8 м², 8 460 000 ₽, было 8 700 000 ₽\n"
            ."— кв. 40 в ЖК «Тёплые холмы»: 2-комн., 54,3 м², 8 900 000 ₽\n\n"
            .'Напишите, если хотите узнать о какой-то подробнее или записаться на просмотр.'
        )
        ->and($conversation->id)->not->toBe($search->conversation_id)
        ->and($conversation->contact_id)->toBe($search->contact_id)
        ->and($conversation->status)->toBe(ConversationStatus::Ai)
        ->and($conversation->dialog['shown_flat_ids'])->toBe([$cheaper->id, $new->id])
        ->and($search->fresh()->last_notified_at->toDateTimeString())->toBe('2026-10-25 12:00:00');
    Queue::assertPushed(DeliverMessage::class, fn (DeliverMessage $job): bool => $job->message->is($message));
});

it('tells about none of the flats that did not change since the subscription, do not fit, got dearer or are not on sale', function () {
    // Conditions of any status, as the agent may keep them: the news are of flats on sale all the same.
    subscription($this->tenant, ['filters' => ['rooms' => [2], 'price_to' => 9_000_000, 'statuses' => []]]);
    flatOnSale($this->building, ['price' => 8_000_000], at: '2026-10-24 11:00:00');
    flatOnSale($this->building, ['rooms' => 3, 'price' => 8_000_000]);
    flatOnSale($this->building, ['price' => 9_500_000]);
    $dearer = Flat::factory()->for($this->building)->create(['rooms' => 2, 'price' => 8_500_000]);
    priceChange($dearer, from: 8_000_000);
    $sold = Flat::factory()->for($this->building)->create(['rooms' => 2, 'price' => 8_000_000, 'status' => FlatStatus::Sold]);
    priceChange($sold, from: 8_500_000);
    flatOnSale(Building::factory()->create(), ['price' => 8_000_000]);

    expect(notifySubscribers($this->tenant))->toBe(0)
        ->and(Message::withoutGlobalScopes()->count())->toBe(0);
});

it('writes to a buyer no more than once a day, and the next news are what changed after the last', function () {
    $search = subscription($this->tenant, ['last_notified_at' => '2026-10-25 00:00:00']);
    flatOnSale($this->building, ['price' => 8_000_000], at: '2026-10-24 23:00:00');
    $later = flatOnSale($this->building, ['number' => '7', 'price' => 8_100_000], at: '2026-10-25 08:00:00');

    expect(notifySubscribers($this->tenant))->toBe(0);

    $this->travelTo('2026-10-26 01:00:00');

    expect(notifySubscribers($this->tenant))->toBe(1)
        ->and(Message::withoutGlobalScopes()->sole()->text)->toContain('кв. 7 ')
        ->and(Message::withoutGlobalScopes()->sole()->text)->not->toContain('8 000 000');
    expect($search->fresh()->last_notified_at->toDateTimeString())->toBe('2026-10-26 01:00:00');
});

it('writes nothing to a buyer who unsubscribed', function () {
    subscription($this->tenant, ['active' => false]);
    flatOnSale($this->building, ['price' => 8_000_000]);

    expect(notifySubscribers($this->tenant))->toBe(0);
});

it('tells the test dialog it was subscribed in, without sending anything to a channel', function () {
    $conversation = Conversation::factory()->for($this->tenant)->create(['dialog' => ['wishes' => 'тихий двор']]);
    SavedSearch::factory()->for($conversation)->create(['created_at' => '2026-10-24 12:00:00']);
    $flat = flatOnSale($this->building, ['price' => 8_000_000]);

    notifySubscribers($this->tenant);

    expect(Message::withoutGlobalScopes()->sole()->conversation_id)->toBe($conversation->id)
        ->and($conversation->fresh()->dialog)->toBe(['wishes' => 'тихий двор', 'shown_flat_ids' => [$flat->id]]);
    Queue::assertNothingPushed();
});

it('names five flats at most, and only of complexes with the places the buyer wants near', function () {
    $school = Complex::factory()->for($this->tenant)->create(['name' => 'Речная терраса']);
    $school->pois()->attach(Poi::factory()->create(['type' => PoiType::School]), ['tenant_id' => $this->tenant->id, 'distance_m' => 400, 'walk_min' => 6]);
    $nearSchool = Building::factory()->for($school)->create();
    subscription($this->tenant, ['filters' => ['rooms' => [2], 'price_to' => 9_000_000, 'nearby' => ['school'], 'statuses' => ['available']]]);
    foreach (range(1, 6) as $number) {
        flatOnSale($nearSchool, ['number' => (string) $number, 'price' => 8_000_000 + $number * 10_000]);
    }
    flatOnSale($this->building, ['number' => '99', 'price' => 7_000_000]);

    notifySubscribers($this->tenant);

    $text = Message::withoutGlobalScopes()->sole()->text;
    expect(substr_count($text, '— кв.'))->toBe(5)
        ->and($text)->not->toContain('кв. 6 ')
        ->and($text)->not->toContain('кв. 99 ');
});
