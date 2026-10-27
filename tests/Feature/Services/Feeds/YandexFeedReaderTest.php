<?php

use App\Enums\Finishing;
use App\Enums\FlatStatus;
use App\Services\Feeds\FeedException;
use App\Services\Feeds\FeedOffer;
use App\Services\Feeds\InvalidOffer;
use App\Services\Feeds\YandexFeedReader;
use Illuminate\Support\Str;

/**
 * Read the feed with the reader and collect its offers, keyed by their position in the feed.
 *
 * @return array<int, FeedOffer|InvalidOffer>
 */
function readYandexFeed(string $feed): array
{
    $path = tempnam(sys_get_temp_dir(), 'feed-test-');
    file_put_contents($path, $feed);

    try {
        return iterator_to_array(app(YandexFeedReader::class)->read($path));
    } finally {
        unlink($path);
    }
}

it('translates an offer into the attributes of its complex, building and flat', function () {
    $feed = yandexFeed(yandexOffer('OK-1-12', ['balcony' => 'лоджия', 'bathroom-unit' => 'совмещенный']));

    $offers = readYandexFeed($feed);

    expect($offers)->toHaveCount(1)
        ->and($offers[1]->complex)->toBe([
            'external_id' => '990101',
            'name' => 'Отрадный квартал',
        ])
        ->and($offers[1]->building)->toBe([
            'external_id' => '9901011',
            'name' => 'корпус 1',
            'address' => 'Москва, Сигнальный проезд, 19к1',
            'latitude' => 55.85421,
            'longitude' => 37.59312,
            'floors' => 17,
            'deadline' => '2026-12-31',
        ])
        ->and($offers[1]->flat)->toBe([
            'external_id' => 'OK-1-12',
            'number' => '12',
            'floor' => 5,
            'rooms' => 1,
            'is_euro' => false,
            'area' => 38.5,
            'kitchen_area' => 10.2,
            'finishing' => Finishing::Finished,
            'price' => 12_500_000,
            'status' => FlatStatus::Available,
            'plan_url' => 'https://developer.test/plans/1-38.svg',
            'description' => 'Однокомнатная квартира 38,5 м² с окнами во двор.',
            'features' => [
                'window_view' => 'во двор',
                'balcony' => 'лоджия',
                'bathroom_unit' => 'совмещенный',
                'ceiling_height' => 2.8,
            ],
        ]);
});

it('counts a kitchen of 15 m² or more as a room of a euro layout', function (string $kitchenArea, int $rooms, bool $isEuro) {
    $feed = yandexFeed(yandexOffer(elements: ['rooms' => '1', 'kitchen-space' => ['value' => $kitchenArea]]));

    $offers = readYandexFeed($feed);

    expect($offers[1]->flat)
        ->rooms->toBe($rooms)
        ->is_euro->toBe($isEuro);
})->with([
    'kitchen just under 15 m²' => ['14.9', 1, false],
    'kitchen-living room of 15 m²' => ['15', 2, true],
]);

it('follows the configured kitchen area of euro layouts', function () {
    config(['feeds.euro_kitchen_min_area' => 18.0]);
    $feed = yandexFeed(yandexOffer(elements: ['rooms' => '1', 'kitchen-space' => ['value' => '16']]));

    $offers = readYandexFeed($feed);

    expect($offers[1]->flat)
        ->rooms->toBe(1)
        ->is_euro->toBe(false);
});

it('reads studios and open-plan flats as flats without rooms', function (array $elements, bool $isOpenPlan) {
    $feed = yandexFeed(yandexOffer(elements: $elements));

    $offers = readYandexFeed($feed);

    expect($offers[1]->flat['rooms'])->toBe(0)
        ->and($offers[1]->flat['features']['open_plan'] ?? false)->toBe($isOpenPlan);
})->with([
    'studio' => [['studio' => '1', 'rooms' => null, 'kitchen-space' => null], false],
    'open plan' => [['open-plan' => 'да', 'rooms' => null], true],
]);

it('maps the Yandex finishing names to finishing levels', function (string $renovation, ?Finishing $finishing) {
    $feed = yandexFeed(yandexOffer(elements: ['renovation' => $renovation]));

    $offers = readYandexFeed($feed);

    expect($offers[1]->flat['finishing'])->toBe($finishing);
})->with([
    'без отделки' => ['без отделки', Finishing::Rough],
    'черновая' => ['черновая', Finishing::Rough],
    'предчистовая' => ['предчистовая', Finishing::WhiteBox],
    'whitebox' => ['whitebox', Finishing::WhiteBox],
    'чистовая' => ['чистовая', Finishing::Finished],
    'под ключ' => ['Под ключ', Finishing::Furnished],
    'a name outside the format' => ['дизайнерская', null],
]);

it('multiplies a price per square meter by the area', function () {
    $feed = yandexFeed(yandexOffer(elements: [
        'price' => ['value' => '300000', 'unit' => 'кв. м'],
        'area' => ['value' => '38.5'],
    ]));

    $offers = readYandexFeed($feed);

    expect($offers[1]->flat['price'])->toBe(11_550_000);
});

it('rejects an offer that misses what a flat needs or carries a malformed value and reads on', function (string $internalId, array $elements, ?string $externalId, string $reason) {
    $feed = yandexFeed(yandexOffer($internalId, $elements), yandexOffer('OK-1-13'));

    $offers = readYandexFeed($feed);

    expect($offers[1])->toEqual(new InvalidOffer($externalId, $reason))
        ->and($offers[2])->toBeInstanceOf(FeedOffer::class);
})->with([
    'no internal id' => ['', [], null, 'The offer has no internal-id attribute.'],
    'not for sale' => ['OK-1-12', ['type' => 'аренда'], 'OK-1-12', 'Only offers for sale are imported, but <type> is «аренда».'],
    'not a flat' => ['OK-1-12', ['category' => 'гараж'], 'OK-1-12', 'Only flats are imported, but <category> is «гараж».'],
    'no complex' => ['OK-1-12', ['yandex-building-id' => null], 'OK-1-12', 'The offer has no <yandex-building-id>.'],
    'no building' => ['OK-1-12', ['yandex-house-id' => null], 'OK-1-12', 'The offer has no <yandex-house-id>.'],
    'no price' => ['OK-1-12', ['price' => null], 'OK-1-12', 'The offer has no <price/value>.'],
    'price in dollars' => ['OK-1-12', ['price' => ['currency' => 'USD']], 'OK-1-12', 'Only prices in rubles are imported, but <price/currency> is «USD».'],
    'no floor' => ['OK-1-12', ['floor' => null], 'OK-1-12', 'The offer has no <floor>.'],
    'no rooms' => ['OK-1-12', ['rooms' => null], 'OK-1-12', 'The offer has neither <rooms> nor <studio>.'],
    'malformed number' => ['OK-1-12', ['floor' => '5-й'], 'OK-1-12', '<floor> is not a number: «5-й».'],
    'implausible number' => ['OK-1-12', ['floors-total' => '1700'], 'OK-1-12', '<floors-total> must be from 1 to 200, but is «1700».'],
    'floor plan that is not a web link' => ['OK-1-12', ['image' => 'javascript:alert(1)'], 'OK-1-12', 'The floor plan is not an http(s) link of up to 2048 characters: «javascript:alert(1)».'],
]);

it('fails on a file that is not a well-formed YRL feed', function (string $content, string $message) {
    expect(fn () => readYandexFeed($content))->toThrow(FeedException::class, $message);
})->with([
    'web page' => ['<!DOCTYPE html><html><body>Not found</body></html>', "The feed's root element is <html>, not <realty-feed>."],
    'plain text' => ['Service temporarily unavailable', 'The feed is not well-formed XML'],
    'feed cut off after an offer' => [
        fn (): string => Str::before(yandexFeed(yandexOffer('OK-1-12'), yandexOffer('OK-1-13')), '<offer internal-id="OK-1-13">'),
        'The feed is not well-formed XML',
    ],
    'feed cut off inside an offer' => [
        fn (): string => Str::beforeLast(yandexFeed(yandexOffer('OK-1-12'), yandexOffer('OK-1-13')), '<floor>'),
        'The feed is not well-formed XML',
    ],
]);
