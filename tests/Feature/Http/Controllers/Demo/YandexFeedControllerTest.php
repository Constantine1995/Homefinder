<?php

use App\Demo\Feed\DemoFeedClock;
use Illuminate\Support\Facades\Storage;

it('serves the feed as of the current day of the sales simulation', function () {
    Storage::fake('local');
    app(DemoFeedClock::class)->advance(7);

    $response = $this->get(route('demo.feeds.yandex'));

    $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    expect((string) simplexml_load_string($response->getContent())->{'generation-date'})
        ->toBe('2026-10-26T09:00:00+03:00');
});

it('links floor plans to the application url when the feed is requested through another host', function () {
    Storage::fake('local');
    config(['app.url' => 'https://homefinder.test']);

    $response = $this->get('http://nginx/demo/feeds/yandex.xml');

    $planUrls = array_map('strval', simplexml_load_string($response->getContent())->xpath('//*[local-name()="image"]'));
    expect($planUrls)->not->toBeEmpty()
        ->each->toStartWith('https://homefinder.test/demo/plans/');
});

it('serves the feed without setting cookies', function () {
    Storage::fake('local');

    $response = $this->get(route('demo.feeds.yandex'));

    $response->assertOk();
    expect($response->headers->getCookies())->toBe([]);
});
