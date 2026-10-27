<?php

use App\Enums\FlatStatus;
use App\Models\Building;
use App\Models\Channel;
use App\Models\Complex;
use App\Models\FeedSource;
use App\Models\Flat;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Build a feed in the Yandex Realty format for new buildings out of the given offers.
 */
function yandexFeed(string ...$offers): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'
        .'<realty-feed xmlns="http://webmaster.yandex.ru/schemas/feed/realty/2010-06">'
        .'<generation-date>2026-10-19T09:00:00+03:00</generation-date>'
        .implode('', $offers)
        .'</realty-feed>';
}

/**
 * Build an offer of a one-room flat for sale in the Yandex Realty format.
 *
 * @param  array<string, mixed>  $elements  Elements to add or replace: nested arrays for nested elements, null to leave an element out.
 */
function yandexOffer(string $internalId = 'OK-1-12', array $elements = []): string
{
    $defaults = [
        'type' => 'продажа',
        'property-type' => 'жилая',
        'category' => 'квартира',
        'location' => [
            'address' => 'Москва, Сигнальный проезд, 19к1',
            'apartment' => '12',
            'latitude' => '55.854210',
            'longitude' => '37.593120',
        ],
        'price' => ['value' => '12500000', 'currency' => 'RUB'],
        'area' => ['value' => '38.5', 'unit' => 'кв. м'],
        'kitchen-space' => ['value' => '10.2', 'unit' => 'кв. м'],
        'image' => 'https://developer.test/plans/1-38.svg',
        'renovation' => 'чистовая',
        'description' => 'Однокомнатная квартира 38,5 м² с окнами во двор.',
        'rooms' => '1',
        'floor' => '5',
        'floors-total' => '17',
        'window-view' => 'во двор',
        'ceiling-height' => '2.80',
        'building-name' => 'Отрадный квартал',
        'yandex-building-id' => '990101',
        'yandex-house-id' => '9901011',
        'building-section' => 'корпус 1',
        'built-year' => '2026',
        'ready-quarter' => '4',
    ];

    return '<offer internal-id="'.e($internalId).'">'.yandexElements(array_replace_recursive($defaults, $elements)).'</offer>';
}

/**
 * @param  array<string, mixed>  $elements
 */
function yandexElements(array $elements): string
{
    $xml = '';

    foreach ($elements as $name => $value) {
        $xml .= match (true) {
            $value === null => '',
            is_array($value) => "<{$name}>".yandexElements($value)."</{$name}>",
            $name === 'image' => '<image tag="plan">'.htmlspecialchars($value, ENT_XML1).'</image>',
            default => "<{$name}>".htmlspecialchars($value, ENT_XML1)."</{$name}>",
        };
    }

    return $xml;
}

/**
 * Create a Yandex feed source whose URL answers each request with the next of the given feeds or responses.
 *
 * The agent service, which a completed import asks for the vectors of the flats, computes none.
 *
 * @param  string|PromiseInterface|Closure  ...$responses  Feeds, responses made with Http::response() or failures such as Http::failedConnection().
 */
function feedSourceServing(string|PromiseInterface|Closure ...$responses): FeedSource
{
    Http::preventStrayRequests();
    Http::fake([
        'https://developer.test/feed.xml' => Http::sequence(array_map(
            fn (string|PromiseInterface|Closure $response): PromiseInterface|Closure => is_string($response) ? Http::response($response) : $response,
            $responses,
        )),
        'agent.invalid/v1/flats/embeddings' => Http::response(['model' => 'test', 'flats' => []]),
    ]);

    return FeedSource::factory()->create(['url' => 'https://developer.test/feed.xml']);
}

/**
 * Build a row of an availability spreadsheet for a one-room flat on sale, keyed by the column headers.
 *
 * @param  array<string, mixed>  $values  Columns to add or replace; null leaves a column out.
 * @return array<string, mixed>
 */
function sheetRow(array $values = []): array
{
    $defaults = [
        'ЖК' => 'Отрадный квартал',
        'Корпус' => 'Корпус 1',
        '№ кв.' => 12,
        'Этаж' => 5,
        'Комнат' => 1,
        'Площадь, м²' => 38.5,
        'Цена, ₽' => 12_500_000,
        'Статус' => 'Свободна',
    ];

    return array_filter(array_replace($defaults, $values), fn (mixed $value): bool => $value !== null);
}

/**
 * Write the rows into a temporary XLSX workbook under a header made of their keys and get the file's path.
 *
 * @param  list<array<string, mixed>>  $rows  Rows keyed by header; a column a row leaves out stays empty in it.
 * @param  list<list<mixed>>  $topRows  Rows above the header, such as a title; an empty list is an empty row.
 */
function spreadsheetFile(array $rows, array $topRows = []): string
{
    $path = test()->temporaryPath('xlsx');
    $headers = array_keys(array_merge(...$rows));
    $dateStyle = (new Style)->setFormat('dd.mm.yyyy');

    $writer = new Writer;
    $writer->openToFile($path);

    foreach ($topRows as $values) {
        $writer->addRow(Row::fromValues($values));
    }

    $writer->addRow(Row::fromValues($headers));

    foreach ($rows as $row) {
        $writer->addRow(new Row(array_map(
            fn (string $header): Cell => Cell::fromValue($row[$header] ?? null, ($row[$header] ?? null) instanceof DateTimeInterface ? $dateStyle : null),
            $headers,
        )));
    }

    $writer->close();

    return $path;
}

/**
 * Write the text into a temporary CSV file and get the file's path.
 */
function csvFile(string $contents): string
{
    $path = test()->temporaryPath('csv');
    file_put_contents($path, $contents);

    return $path;
}

/**
 * Build a GeoJSON feature the way osmium exports an OpenStreetMap object, as one line of a GeoJSON text sequence.
 *
 * @param  string  $id  osmium's feature ID: «n12» for node 12, «a24» for the area of way 12.
 * @param  array<string, mixed>  $geometry
 * @param  array<string, string>  $tags
 */
function osmFeature(string $id, array $geometry, array $tags): string
{
    return json_encode(['type' => 'Feature', 'id' => $id, 'geometry' => $geometry, 'properties' => $tags], JSON_UNESCAPED_UNICODE)."\n";
}

/**
 * @return array{type: string, coordinates: array{float, float}}
 */
function osmPoint(float $latitude, float $longitude): array
{
    return ['type' => 'Point', 'coordinates' => [$longitude, $latitude]];
}

/**
 * Build a square area whose south-west corner is at the point and whose side is given in degrees.
 *
 * @return array{type: string, coordinates: list<list<list<list<float>>>>}
 */
function osmSquare(float $latitude, float $longitude, float $side): array
{
    return ['type' => 'MultiPolygon', 'coordinates' => [[[
        [$longitude, $latitude],
        [$longitude + $side, $latitude],
        [$longitude + $side, $latitude + $side],
        [$longitude, $latitude + $side],
        [$longitude, $latitude],
    ]]]];
}

/**
 * Write the features into a temporary GeoJSON text sequence and get the file's path.
 */
function osmExportFile(string ...$features): string
{
    $path = test()->temporaryPath('geojsonseq');
    file_put_contents($path, implode('', $features));

    return $path;
}

/**
 * Fake the Bot API: every method succeeds, and a sent message gets ID 99.
 */
function fakeTelegram(): void
{
    Http::preventStrayRequests();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]])]);
}

/**
 * Get the parameters of the calls made of a Bot API method, in order.
 *
 * @return list<array<string, mixed>>
 */
function telegramCalls(string $method): array
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), "/{$method}"))
        ->map(fn (array $pair): array => $pair[0]->data())
        ->values()
        ->all();
}

/**
 * An update with a buyer's message in a private chat with the bot.
 *
 * @param  array<string, mixed>  $message  Fields of the message to add or replace.
 * @return array<string, mixed>
 */
function telegramMessage(string $text = 'двушка до 9 млн', int $chatId = 5001, int $updateId = 1, array $message = []): array
{
    return ['update_id' => $updateId, 'message' => array_replace([
        'message_id' => 10,
        'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Анна', 'last_name' => 'Петрова', 'username' => 'anna_p'],
        'chat' => ['id' => $chatId, 'type' => 'private'],
        'date' => 1_793_000_000,
        'text' => $text,
    ], $message)];
}

/**
 * A two-room flat on sale in the channel's developer's complex «Отрадный квартал», building 1, flat 12.
 */
function flatOfChannel(Channel $channel, FlatStatus $status = FlatStatus::Available): Flat
{
    $complex = Complex::factory()->for($channel->tenant)->create(['name' => 'Отрадный квартал']);
    $building = Building::factory()->for($complex)->create(['name' => 'Корпус 1', 'floors' => 17, 'deadline' => '2027-12-31']);

    return Flat::factory()->for($building)->create([
        'number' => '12', 'rooms' => 2, 'is_euro' => false, 'area' => 54.1, 'price' => 8_762_000, 'status' => $status,
    ]);
}
