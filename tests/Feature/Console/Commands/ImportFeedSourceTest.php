<?php

use App\Models\FeedSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('imports the feed source and prints the import report', function () {
    $source = feedSourceServing(yandexFeed(yandexOffer('OK-1-12'), yandexOffer('OK-1-13', ['price' => null])));

    $this->artisan('feeds:import', ['source' => $source->id])
        ->expectsOutputToContain("Feed source {$source->id} imported.")
        ->expectsTable(['Flats', 'Count'], [
            ['Added', 1],
            ['Updated', 0],
            ['Withdrawn', 0],
            ['Rejected offers', 1],
        ])
        ->expectsTable(['Offer', 'Internal ID', 'Reason'], [
            [2, 'OK-1-13', 'The offer has no <price/value>.'],
        ])
        ->assertSuccessful();

    $this->assertDatabaseHas('flats', ['feed_source_id' => $source->id, 'external_id' => 'OK-1-12']);
});

it('reports why the import failed', function () {
    $source = feedSourceServing(Http::response('Bad gateway', 502));

    $this->artisan('feeds:import', ['source' => $source->id])
        ->expectsOutputToContain('The import failed: The feed server responded with HTTP 502.')
        ->assertFailed();
});

it('rejects a feed source that does not exist', function (string $source) {
    $this->artisan('feeds:import', ['source' => $source])
        ->expectsOutputToContain("There is no feed source {$source}.")
        ->assertExitCode(Command::INVALID);
})->with([
    'unknown id' => '999',
    'not an id' => 'demo',
]);

it('imports a spreadsheet file into a spreadsheet source and prints the rejected rows', function () {
    Storage::fake('local');
    $source = FeedSource::factory()->uploaded()->create();
    $path = spreadsheetFile([sheetRow(['№ кв.' => 12]), sheetRow(['№ кв.' => 13, 'Этаж' => ''])]);

    $this->artisan('feeds:import', ['source' => $source->id, '--file' => $path])
        ->expectsOutputToContain("Feed source {$source->id} imported.")
        ->expectsTable(['Flats', 'Count'], [
            ['Added', 1],
            ['Updated', 0],
            ['Withdrawn', 0],
            ['Rejected rows', 1],
        ])
        ->expectsTable(['Row', 'Flat ID', 'Reason'], [
            [3, 'отрадный квартал/корпус 1/13', 'The row has no «Этаж».'],
        ])
        ->assertSuccessful();

    $this->assertDatabaseHas('feed_imports', ['feed_source_id' => $source->id, 'file_name' => basename($path)]);
});

it('rejects a file that does not exist', function () {
    $source = FeedSource::factory()->uploaded()->create();

    $this->artisan('feeds:import', ['source' => $source->id, '--file' => '/no/such/sheet.xlsx'])
        ->expectsOutputToContain('There is no file /no/such/sheet.xlsx.')
        ->assertExitCode(Command::INVALID);

    $this->assertDatabaseCount('feed_imports', 0);
});
