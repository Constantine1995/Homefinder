<?php

use App\Jobs\ImportFeed;
use App\Models\FeedSource;
use Illuminate\Support\Facades\Queue;

it('queues an import of an active feed source whose schedule is due', function () {
    $this->travelTo('2026-10-19 10:00:00');
    $source = FeedSource::factory()->create(['schedule' => '0 * * * *']);
    Queue::fake([ImportFeed::class]);

    $this->artisan('feeds:import-due')->assertSuccessful();

    Queue::assertPushed(ImportFeed::class, fn (ImportFeed $job): bool => $job->feedSource->is($source));
});

it('does not queue imports of feed sources that are not due', function (Closure $createSource) {
    $this->travelTo('2026-10-19 10:00:00');
    $createSource();
    Queue::fake([ImportFeed::class]);

    $this->artisan('feeds:import-due')->assertSuccessful();

    Queue::assertNotPushed(ImportFeed::class);
})->with([
    'scheduled for another time' => fn () => FeedSource::factory()->create(['schedule' => '0 9 * * *']),
    'inactive' => fn () => FeedSource::factory()->inactive()->create(),
    'imported by hand only' => fn () => FeedSource::factory()->manual()->create(),
    'uploaded spreadsheet' => fn () => FeedSource::factory()->uploaded()->create(),
]);

it('warns about a feed source with an invalid schedule and queues nothing for it', function () {
    $this->travelTo('2026-10-19 10:00:00');
    $source = FeedSource::factory()->create(['schedule' => 'every hour']);
    Queue::fake([ImportFeed::class]);

    $this->artisan('feeds:import-due')
        ->expectsOutputToContain("Feed source {$source->id} has an invalid schedule «every hour».")
        ->assertSuccessful();

    Queue::assertNotPushed(ImportFeed::class);
});
