<?php

use App\Demo\Feed\YandexFeedWriter;
use App\Enums\FeedImportStatus;
use App\Enums\FeedType;
use App\Enums\FlatEventField;
use App\Enums\FlatStatus;
use App\Jobs\EmbedFlats;
use App\Jobs\LocateComplexes;
use App\Jobs\NotifySavedSearches;
use App\Models\Building;
use App\Models\Complex;
use App\Models\FeedImport;
use App\Models\FeedSource;
use App\Models\Flat;
use App\Models\Tenant;
use App\Services\Feeds\FeedImporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Get the prices of the feed's offers keyed by their internal IDs, read straight from the XML.
 *
 * @return array<string, int>
 */
function offerPrices(string $feed): array
{
    $prices = [];

    foreach (simplexml_load_string($feed)->offer as $offer) {
        $prices[(string) $offer['internal-id']] = (int) $offer->price->value;
    }

    return $prices;
}

it('imports the demo feed into complexes, buildings and flats of the source\'s tenant', function () {
    $feed = app(YandexFeedWriter::class)->write(0);
    $source = feedSourceServing($feed);

    $import = app(FeedImporter::class)->import($source);

    $offerCount = count(offerPrices($feed));
    expect($import)
        ->status->toBe(FeedImportStatus::Completed)
        ->added_count->toBe($offerCount)
        ->updated_count->toBe(0)
        ->removed_count->toBe(0)
        ->failed_count->toBe(0)
        ->row_errors->toBe([]);
    expect(Flat::pluck('price', 'external_id')->sortKeys()->all())->toBe(collect(offerPrices($feed))->sortKeys()->all());
    expect(Flat::distinct()->pluck('tenant_id')->all())->toBe([$source->tenant_id]);
    expect(Complex::orderBy('name')->pluck('name')->all())->toBe(['Отрадный квартал', 'Речная терраса', 'Тёплые холмы']);
    expect(Building::count())->toBe(6);
    expect($import->flatEvents()->count())->toBe(2 * $offerCount);
});

it('places a complex at its first building and completes it with its last building', function () {
    $source = feedSourceServing(app(YandexFeedWriter::class)->write(0));

    app(FeedImporter::class)->import($source);

    expect(Complex::firstWhere('external_id', '990101'))
        ->address->toBe('Москва, Сигнальный проезд, 19к1')
        ->latitude->toBe(55.85421)
        ->longitude->toBe(37.59312)
        ->deadline->toDateString()->toBe('2027-12-31');
});

it('reports the flats added, repriced and withdrawn between two days of the demo feed', function () {
    $dayZero = app(YandexFeedWriter::class)->write(0);
    $daySeven = app(YandexFeedWriter::class)->write(7);
    $source = feedSourceServing($dayZero, $daySeven);
    app(FeedImporter::class)->import($source);

    $import = app(FeedImporter::class)->import($source);

    $pricesBefore = offerPrices($dayZero);
    $pricesAfter = offerPrices($daySeven);
    $addedIds = array_keys(array_diff_key($pricesAfter, $pricesBefore));
    $withdrawnIds = array_keys(array_diff_key($pricesBefore, $pricesAfter));
    $repricedIds = array_keys(array_filter(
        array_intersect_key($pricesAfter, $pricesBefore),
        fn (int $price, string $id): bool => $price !== $pricesBefore[$id],
        ARRAY_FILTER_USE_BOTH,
    ));
    expect($import)
        ->added_count->toBe(count($addedIds))
        ->updated_count->toBe(count($repricedIds))
        ->removed_count->toBe(count($withdrawnIds));
    expect(Flat::where('status', FlatStatus::Available)->pluck('price', 'external_id')->sortKeys()->all())
        ->toBe(collect($pricesAfter)->sortKeys()->all());
    expect(Flat::where('status', FlatStatus::Withdrawn)->pluck('external_id')->sort()->values()->all())
        ->toBe(collect($withdrawnIds)->sort()->values()->all());
    expect($import->flatEvents()->where('field', FlatEventField::Status)->count())->toBe(count($addedIds) + count($withdrawnIds))
        ->and($import->flatEvents()->where('field', FlatEventField::Price)->count())->toBe(count($addedIds) + count($repricedIds));
});

it('changes nothing when the feed has not changed', function () {
    $feed = app(YandexFeedWriter::class)->write(0);
    $source = feedSourceServing($feed, $feed);
    app(FeedImporter::class)->import($source);

    $import = app(FeedImporter::class)->import($source);

    expect($import)
        ->status->toBe(FeedImportStatus::Completed)
        ->added_count->toBe(0)
        ->updated_count->toBe(0)
        ->removed_count->toBe(0);
    expect($import->flatEvents()->count())->toBe(0);
});

it('records a price change with the import that found it', function () {
    $source = feedSourceServing(
        yandexFeed(yandexOffer('OK-1-12', ['price' => ['value' => '12500000']])),
        yandexFeed(yandexOffer('OK-1-12', ['price' => ['value' => '11900000']])),
    );
    app(FeedImporter::class)->import($source);

    $import = app(FeedImporter::class)->import($source);

    $flat = Flat::sole();
    expect($import->updated_count)->toBe(1)
        ->and($flat->price)->toBe(11_900_000)
        ->and($import->flatEvents()->sole())
        ->flat_id->toBe($flat->id)
        ->field->toBe(FlatEventField::Price)
        ->old_value->toBe(12_500_000)
        ->new_value->toBe(11_900_000);
});

it('withdraws a flat that left the feed and keeps it with its history', function () {
    $source = feedSourceServing(
        yandexFeed(yandexOffer('OK-1-12'), yandexOffer('OK-1-13')),
        yandexFeed(yandexOffer('OK-1-12')),
    );
    app(FeedImporter::class)->import($source);

    $import = app(FeedImporter::class)->import($source);

    $flat = Flat::firstWhere('external_id', 'OK-1-13');
    expect($import->removed_count)->toBe(1)
        ->and($flat->status)->toBe(FlatStatus::Withdrawn)
        ->and($import->flatEvents()->sole())
        ->flat_id->toBe($flat->id)
        ->field->toBe(FlatEventField::Status)
        ->old_value->toBe('available')
        ->new_value->toBe('withdrawn');
});

it('puts a withdrawn flat back on sale when it returns to the feed', function () {
    $source = feedSourceServing(
        yandexFeed(yandexOffer('OK-1-12'), yandexOffer('OK-1-13')),
        yandexFeed(yandexOffer('OK-1-12')),
        yandexFeed(yandexOffer('OK-1-12'), yandexOffer('OK-1-13')),
    );
    app(FeedImporter::class)->import($source);
    app(FeedImporter::class)->import($source);

    $import = app(FeedImporter::class)->import($source);

    expect($import)
        ->added_count->toBe(0)
        ->updated_count->toBe(1)
        ->and(Flat::firstWhere('external_id', 'OK-1-13')->status)->toBe(FlatStatus::Available)
        ->and($import->flatEvents()->sole())
        ->old_value->toBe('withdrawn')
        ->new_value->toBe('available');
});

it('rejects invalid and repeated offers and imports the rest', function () {
    $source = feedSourceServing(yandexFeed(
        yandexOffer('OK-1-12'),
        yandexOffer('OK-1-13', ['price' => null]),
        yandexOffer('OK-1-12', ['floor' => '7']),
    ));

    $import = app(FeedImporter::class)->import($source);

    expect($import)
        ->status->toBe(FeedImportStatus::Completed)
        ->added_count->toBe(1)
        ->failed_count->toBe(2)
        ->row_errors->toBe([
            ['row' => 2, 'external_id' => 'OK-1-13', 'message' => 'The offer has no <price/value>.'],
            ['row' => 3, 'external_id' => 'OK-1-12', 'message' => 'An earlier offer has the same flat ID.'],
        ]);
    expect(Flat::sole())
        ->external_id->toBe('OK-1-12')
        ->floor->toBe(5);
});

it('fails without changing the catalog when the feed is broken', function (Closure $brokenResponse, string $message) {
    $source = feedSourceServing(yandexFeed(yandexOffer('OK-1-12'), yandexOffer('OK-1-13')), $brokenResponse());
    $this->travelTo('2026-10-19 10:00:00');
    app(FeedImporter::class)->import($source);
    $this->travelTo('2026-10-19 11:00:00');

    $import = app(FeedImporter::class)->import($source);

    expect($import)
        ->status->toBe(FeedImportStatus::Failed)
        ->error_message->toContain($message)
        ->finished_at->toDateTimeString()->toBe('2026-10-19 11:00:00');
    expect($import->flatEvents()->count())->toBe(0);
    expect(Flat::orderBy('external_id')->pluck('status', 'external_id')->all())
        ->toBe(['OK-1-12' => FlatStatus::Available, 'OK-1-13' => FlatStatus::Available]);
    expect($source->fresh())
        ->last_status->toBe(FeedImportStatus::Failed)
        ->last_imported_at->toDateTimeString()->toBe('2026-10-19 10:00:00');
})->with([
    'unreachable server' => [fn () => Http::failedConnection(), 'The feed could not be downloaded'],
    'server error' => [fn () => Http::response('Bad gateway', 502), 'The feed server responded with HTTP 502.'],
    'feed cut off' => [
        fn (): string => Str::beforeLast(yandexFeed(yandexOffer('OK-1-12'), yandexOffer('OK-1-14')), '<floor>'),
        'The feed is not well-formed XML at offer 2',
    ],
    'no valid offers' => [
        fn (): string => yandexFeed(yandexOffer('OK-1-12', ['price' => null])),
        "None of the feed's 1 offers is valid. The first one was rejected: The offer has no <price/value>.",
    ],
]);

it('leaves the catalog as it was when a long feed turns out broken near its end', function () {
    $source = feedSourceServing(
        app(YandexFeedWriter::class)->write(0),
        Str::beforeLast(app(YandexFeedWriter::class)->write(7), '<floor>'),
    );
    app(FeedImporter::class)->import($source);
    $catalogBefore = Flat::orderBy('external_id')->get(['external_id', 'status', 'price'])->toArray();

    $import = app(FeedImporter::class)->import($source);

    expect($import->status)->toBe(FeedImportStatus::Failed)
        ->and(Flat::orderBy('external_id')->get(['external_id', 'status', 'price'])->toArray())->toBe($catalogBefore);
});

it('fails an import left running by a killed worker', function () {
    $source = feedSourceServing(yandexFeed(yandexOffer()));
    $interruptedImport = FeedImport::factory()->for($source)->running()->create();

    app(FeedImporter::class)->import($source);

    expect($interruptedImport->fresh())
        ->status->toBe(FeedImportStatus::Failed)
        ->error_message->toBe('The import was interrupted before it finished.');
});

it('skips the import while another import of the source is running', function () {
    Http::preventStrayRequests();
    $source = FeedSource::factory()->create();
    Cache::lock("feed-import:{$source->id}", 60)->get();

    $import = app(FeedImporter::class)->import($source);

    expect($import)->toBeNull();
    $this->assertDatabaseCount('feed_imports', 0);
});

it('imports an uploaded spreadsheet with the statuses of its flats and keeps the file with the import', function () {
    Storage::fake('local');
    $source = FeedSource::factory()->uploaded()->create();
    $path = spreadsheetFile([
        sheetRow(['№ кв.' => 12, 'Статус' => 'Свободна']),
        sheetRow(['№ кв.' => 13, 'Статус' => 'Бронь']),
        sheetRow(['№ кв.' => 14, 'Статус' => 'Продана']),
    ]);

    $import = app(FeedImporter::class)->importFile($source, $path, 'Шахматка на 20.10.xlsx');

    expect($import)
        ->status->toBe(FeedImportStatus::Completed)
        ->added_count->toBe(3)
        ->file_name->toBe('Шахматка на 20.10.xlsx')
        ->file_path->toBe("feed-uploads/{$source->id}/{$import->id}.xlsx");
    expect(Storage::disk('local')->get($import->file_path))->toBe(file_get_contents($path));
    expect(Flat::orderBy('number')->pluck('status', 'number')->all())->toBe([
        '12' => FlatStatus::Available,
        '13' => FlatStatus::Reserved,
        '14' => FlatStatus::Sold,
    ]);
    expect($import->flatEvents()->where('field', FlatEventField::Status)->pluck('new_value')->sort()->values()->all())
        ->toBe(['available', 'reserved', 'sold']);
});

it('records the sale of a reserved flat and withdraws a flat the next spreadsheet leaves out', function () {
    Storage::fake('local');
    $source = FeedSource::factory()->uploaded()->create();
    app(FeedImporter::class)->importFile($source, spreadsheetFile([
        sheetRow(['№ кв.' => 12, 'Статус' => 'Бронь']),
        sheetRow(['№ кв.' => 13, 'Статус' => 'Свободна']),
    ]), 'day-0.xlsx');

    $import = app(FeedImporter::class)->importFile($source, spreadsheetFile([
        sheetRow(['№ кв.' => 12, 'Статус' => 'Продана']),
    ]), 'day-1.xlsx');

    expect($import)
        ->updated_count->toBe(1)
        ->removed_count->toBe(1);
    expect(Flat::orderBy('number')->pluck('status', 'number')->all())
        ->toBe(['12' => FlatStatus::Sold, '13' => FlatStatus::Withdrawn]);
    expect($import->flatEvents()->orderBy('old_value')->get(['old_value', 'new_value'])->toArray())->toBe([
        ['old_value' => 'available', 'new_value' => 'withdrawn'],
        ['old_value' => 'reserved', 'new_value' => 'sold'],
    ]);
});

it('fails an uploaded spreadsheet without a header row and keeps the file for a look', function () {
    Storage::fake('local');
    $source = FeedSource::factory()->uploaded()->create();

    $import = app(FeedImporter::class)->importFile($source, csvFile("Квартира;Цена\n12;12500000\n"), 'prices.csv');

    expect($import)
        ->status->toBe(FeedImportStatus::Failed)
        ->error_message->toStartWith('None of the top 10 rows of the sheet is a header row');
    Storage::disk('local')->assertExists($import->file_path);
});

it('keeps the files of the latest uploads of a source only', function () {
    Storage::fake('local');
    config(['feeds.kept_uploads' => 2]);
    $source = FeedSource::factory()->uploaded()->create();
    $otherSource = FeedSource::factory()->uploaded()->create();
    $otherImport = app(FeedImporter::class)->importFile($otherSource, spreadsheetFile([sheetRow()]), 'other.xlsx');
    $imports = collect(range(1, 3))->map(
        fn (int $day): FeedImport => app(FeedImporter::class)->importFile($source, spreadsheetFile([sheetRow()]), "day-{$day}.xlsx"),
    );

    $oldestImport = $imports->first()->fresh();

    expect($oldestImport)
        ->file_name->toBe('day-1.xlsx')
        ->file_path->toBeNull();
    Storage::disk('local')->assertMissing($imports->first()->file_path);
    Storage::disk('local')->assertExists([$imports[1]->file_path, $imports[2]->file_path, $otherImport->file_path]);
});

it('stores nothing while another import of the source is running', function () {
    Storage::fake('local');
    $source = FeedSource::factory()->uploaded()->create();
    Cache::lock("feed-import:{$source->id}", 60)->get();

    $import = app(FeedImporter::class)->importFile($source, spreadsheetFile([sheetRow()]), 'sheet.xlsx');

    expect($import)->toBeNull();
    Storage::disk('local')->assertDirectoryEmpty('/');
});

it('downloads a spreadsheet published at the URL of a spreadsheet source', function () {
    $source = feedSourceServing("ЖК;Корпус;№ кв.;Этаж;Комнат;Площадь;Цена\nОтрадный квартал;Корпус 1;12;5;1;38,5;12500000\n");
    $source->update(['type' => FeedType::Csv]);

    $import = app(FeedImporter::class)->import($source);

    expect($import)
        ->status->toBe(FeedImportStatus::Completed)
        ->added_count->toBe(1)
        ->file_path->toBeNull();
});

it('adds the buildings of a spreadsheet to a complex of the feed when the sheet gives the feed\'s complex ID', function () {
    Storage::fake('local');
    app(FeedImporter::class)->import(feedSourceServing(yandexFeed(yandexOffer('OK-1-12'))));
    $sheetSource = FeedSource::factory()->uploaded()->recycle(Tenant::first())->create();

    app(FeedImporter::class)->importFile($sheetSource, spreadsheetFile([
        sheetRow(['ID ЖК' => '990101', 'Корпус' => 'Корпус 3']),
    ]), 'sheet.xlsx');

    expect(Complex::sole()->external_id)->toBe('990101')
        ->and(Building::orderBy('external_id')->pluck('name', 'external_id')->all())->toBe(['9901011' => 'корпус 1', 'корпус 3' => 'Корпус 3']);
});

it('keeps a complex of a spreadsheet apart from the feed\'s one of the same name when the sheet gives no complex ID', function () {
    Storage::fake('local');
    app(FeedImporter::class)->import(feedSourceServing(yandexFeed(yandexOffer('OK-1-12'))));
    $sheetSource = FeedSource::factory()->uploaded()->recycle(Tenant::first())->create();

    app(FeedImporter::class)->importFile($sheetSource, spreadsheetFile([sheetRow(['ЖК' => 'Отрадный квартал'])]), 'sheet.xlsx');

    expect(Complex::orderBy('external_id')->pluck('name', 'external_id')->all())
        ->toBe(['990101' => 'Отрадный квартал', 'отрадный квартал' => 'Отрадный квартал']);
});

it('queues putting the complexes on the map after a completed import only', function () {
    Queue::fake([LocateComplexes::class]);
    $source = feedSourceServing(yandexFeed(yandexOffer()), Http::response('Bad gateway', 502));

    app(FeedImporter::class)->import($source);
    app(FeedImporter::class)->import($source);

    Queue::assertPushedTimes(LocateComplexes::class, 1);
});

it('queues computing the vectors of the flats after a completed import only', function () {
    Queue::fake([EmbedFlats::class]);
    $source = feedSourceServing(yandexFeed(yandexOffer()), Http::response('Bad gateway', 502));

    app(FeedImporter::class)->import($source);
    app(FeedImporter::class)->import($source);

    Queue::assertPushedTimes(EmbedFlats::class, 1);
});

it('queues the news of the developer\'s subscriptions after a completed import only', function () {
    Queue::fake([NotifySavedSearches::class]);
    $source = feedSourceServing(yandexFeed(yandexOffer()), Http::response('Bad gateway', 502));

    app(FeedImporter::class)->import($source);
    app(FeedImporter::class)->import($source);

    Queue::assertPushedTimes(NotifySavedSearches::class, 1);
    Queue::assertPushed(NotifySavedSearches::class, fn (NotifySavedSearches $job): bool => $job->tenant->is($source->tenant));
});

it('keeps the coordinates the geocoder found while the address of a building stays the same', function () {
    Storage::fake('local');
    Queue::fake([LocateComplexes::class]);
    $source = FeedSource::factory()->uploaded()->create();
    $sheet = spreadsheetFile([sheetRow(['Адрес' => 'Москва, Сигнальный проезд, 19'])]);
    app(FeedImporter::class)->importFile($source, $sheet, 'day-0.xlsx');
    Building::sole()->forceFill(['latitude' => 55.8542, 'longitude' => 37.5931, 'geocoded_at' => now()])->save();

    app(FeedImporter::class)->importFile($source, $sheet, 'day-1.xlsx');

    expect(Building::sole())
        ->latitude->toBe(55.8542)
        ->longitude->toBe(37.5931)
        ->geocoded_at->not->toBeNull();
});

it('forgets the coordinates the geocoder found once the address of a building changes', function () {
    Storage::fake('local');
    Queue::fake([LocateComplexes::class]);
    $source = FeedSource::factory()->uploaded()->create();
    app(FeedImporter::class)->importFile($source, spreadsheetFile([sheetRow(['Адрес' => 'Москва, Сигнальный проезд, 19'])]), 'day-0.xlsx');
    Building::sole()->forceFill(['latitude' => 55.8542, 'longitude' => 37.5931, 'geocoded_at' => now()])->save();

    app(FeedImporter::class)->importFile($source, spreadsheetFile([sheetRow(['Адрес' => 'Москва, Сигнальный проезд, 21'])]), 'day-1.xlsx');

    expect(Building::sole())
        ->latitude->toBeNull()
        ->longitude->toBeNull()
        ->geocoded_at->toBeNull();
});
