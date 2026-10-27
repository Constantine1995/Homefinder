<?php

use App\Services\Catalog\PlanPictures;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('draws an SVG plan into a PNG of the card\'s width', function () {
    Http::preventStrayRequests();
    Http::fake(['developer.test/plans/2-54.svg' => Http::response('<svg/>', 200, ['Content-Type' => 'image/svg+xml; charset=utf-8'])]);
    Process::fake(['*rsvg-convert*' => Process::result("PNG\n")]);

    $picture = app(PlanPictures::class)->picture('https://developer.test/plans/2-54.svg');

    expect($picture)->toBe("PNG\n");
    Process::assertRan(fn ($process): bool => $process->input === '<svg/>'
        && $process->command === ['rsvg-convert', '--format', 'png', '--width', '960', '--keep-aspect-ratio', '--background-color', 'white']);
});

it('gives a PNG or JPEG plan as it is', function () {
    Http::preventStrayRequests();
    Http::fake(['developer.test/plans/2-54.jpg' => Http::response('JPEG', 200, ['Content-Type' => 'image/jpeg'])]);
    Process::fake();

    expect(app(PlanPictures::class)->picture('https://developer.test/plans/2-54.jpg'))->toBe('JPEG');
    Process::assertNothingRan();
});

it('fetches the plans the application serves itself by its internal address', function () {
    config(['app.url' => 'http://localhost:8080', 'app.internal_url' => 'http://nginx']);
    Http::preventStrayRequests();
    Http::fake(['nginx/demo/plans/2-54.svg' => Http::response('PNG', 200, ['Content-Type' => 'image/png'])]);

    app(PlanPictures::class)->picture('http://localhost:8080/demo/plans/2-54.svg');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://nginx/demo/plans/2-54.svg');
});

it('gives no picture for a page that is not one, a missing plan or a failed drawing', function (int $status, string $type, int $exitCode) {
    Http::preventStrayRequests();
    Http::fake(['developer.test/plans/2-54' => Http::response('<html/>', $status, ['Content-Type' => $type])]);
    Process::fake(['*rsvg-convert*' => Process::result(errorOutput: 'Error reading SVG', exitCode: $exitCode)]);

    expect(app(PlanPictures::class)->picture('https://developer.test/plans/2-54'))->toBeNull();
})->with([
    'an HTML page' => [200, 'text/html', 0],
    'a missing plan' => [404, 'image/svg+xml', 0],
    'a failed drawing' => [200, 'image/svg+xml', 1],
]);

it('gives no picture when the plan\'s site cannot be reached', function () {
    Http::preventStrayRequests();
    Http::fake(['developer.test/*' => Http::failedConnection()]);

    expect(app(PlanPictures::class)->picture('https://developer.test/plans/2-54.svg'))->toBeNull();
});
