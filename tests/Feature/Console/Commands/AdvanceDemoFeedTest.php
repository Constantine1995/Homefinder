<?php

use App\Demo\Feed\DemoFeedClock;
use App\Demo\Feed\SalesSimulation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

it('moves the demo feed forward and reports how the flats changed', function () {
    Storage::fake('local');
    $changes = app(SalesSimulation::class)->changesBetween(0, 7);

    $this->artisan('demo-feed:advance', ['days' => 7])
        ->expectsOutputToContain('The demo feed moved from day 0 to day 7.')
        ->expectsTable(['Change', 'Flats'], [
            ['Put on sale', $changes->released],
            ['Back on sale after a reservation', $changes->returned],
            ['Reserved', $changes->reserved],
            ['Sold', $changes->sold],
            ['Cheaper', $changes->cheaper],
            ['More expensive', $changes->pricier],
            ['Listed in the feed', $changes->listed],
        ])
        ->assertSuccessful();

    expect(app(DemoFeedClock::class)->today())->toBe(7);
});

it('rejects a number of days that is not a positive integer', function (string $days) {
    Storage::fake('local');

    $this->artisan('demo-feed:advance', ['days' => $days])
        ->expectsOutputToContain('The number of days must be a positive integer.')
        ->assertExitCode(Command::INVALID);

    expect(app(DemoFeedClock::class)->today())->toBe(0);
})->with([
    'zero' => '0',
    'negative' => '-3',
    'not a number' => 'week',
]);
