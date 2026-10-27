<?php

use App\Demo\Feed\DemoFeedClock;
use Illuminate\Support\Facades\Storage;

it('moves the demo feed back to day 0', function () {
    Storage::fake('local');
    app(DemoFeedClock::class)->advance(5);

    $this->artisan('demo-feed:reset')
        ->expectsOutputToContain('The demo feed is back at day 0.')
        ->assertSuccessful();

    expect(app(DemoFeedClock::class)->today())->toBe(0);
});
