<?php

use App\Enums\PoiType;
use App\Models\Building;
use App\Models\Complex;
use App\Models\Poi;
use App\Services\Geo\ComplexLocator;
use App\Services\Geo\GeoException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Http::preventStrayRequests();
});

/**
 * Answer geocoding requests by address: coordinates as [latitude, longitude], or null for an address not found.
 *
 * @param  array<string, array{float, float}|null>  $places
 */
function geocoderKnowing(array $places): void
{
    Http::fake(['nominatim.openstreetmap.org/*' => function ($request) use ($places) {
        $point = $places[$request['q']] ?? null;

        return Http::response($point === null ? [] : [['lat' => (string) $point[0], 'lon' => (string) $point[1]]]);
    }]);
}

it('geocodes the buildings without coordinates, places their complexes and computes their surroundings', function () {
    $complex = Complex::factory()->create(['address' => null, 'latitude' => null, 'longitude' => null]);
    $building = Building::factory()->for($complex)->unlocated()->create(['address' => 'Москва, Сигнальный проезд, 19']);
    $school = Poi::factory()->create(['type' => PoiType::School, 'geom' => 'SRID=4326;POINT(37.5931 55.8587)']);
    geocoderKnowing(['Москва, Сигнальный проезд, 19' => [55.8542, 37.5931]]);

    $report = app(ComplexLocator::class)->locate();

    expect($report)->toBe(['geocoded' => 1, 'not_found' => 0, 'complexes' => 1]);
    expect($building->fresh())
        ->latitude->toBe(55.8542)
        ->longitude->toBe(37.5931)
        ->geocoded_at->not->toBeNull();
    expect($complex->fresh())
        ->address->toBe('Москва, Сигнальный проезд, 19')
        ->latitude->toBe(55.8542);
    expect($complex->pois()->pluck('pois.id')->all())->toBe([$school->id]);
});

it('does not ask again for an address that was not found until the address changes', function () {
    $building = Building::factory()->unlocated()->create(['address' => 'Сигнальный проезд, 999']);
    geocoderKnowing([]);
    app(ComplexLocator::class)->locate();

    $reportBefore = app(ComplexLocator::class)->locate();
    $building->update(['address' => 'Сигнальный проезд, 19']);
    $reportAfter = app(ComplexLocator::class)->locate();

    expect($reportBefore)->toMatchArray(['geocoded' => 0, 'not_found' => 0])
        ->and($reportAfter)->toMatchArray(['geocoded' => 0, 'not_found' => 1]);
    Http::assertSentCount(2);
});

it('geocodes a limited batch of buildings in one run', function () {
    config(['geo.nominatim.addresses_per_run' => 2]);
    Building::factory()->count(3)->unlocated()->create();
    geocoderKnowing([]);

    $report = app(ComplexLocator::class)->locate();

    expect($report['not_found'])->toBe(2)
        ->and(Building::whereNull('geocoded_at')->count())->toBe(1);
});

it('keeps what it geocoded before the geocoder failed and leaves the rest for the next run', function () {
    $complex = Complex::factory()->create(['latitude' => null, 'longitude' => null]);
    $locatedBuilding = Building::factory()->for($complex)->unlocated()->create(['external_id' => '1', 'address' => 'Сигнальный проезд, 19']);
    $waitingBuilding = Building::factory()->for($complex)->unlocated()->create(['external_id' => '2', 'address' => 'Сигнальный проезд, 21']);
    Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
        ->push([['lat' => '55.8542', 'lon' => '37.5931']])
        ->push('Service unavailable', 503)]);

    expect(fn () => app(ComplexLocator::class)->locate())->toThrow(GeoException::class);
    expect($locatedBuilding->fresh()->latitude)->toBe(55.8542)
        ->and($waitingBuilding->fresh()->geocoded_at)->toBeNull()
        ->and($complex->fresh()->latitude)->toBe(55.8542);
});

it('skips the run while another one is going', function () {
    Building::factory()->unlocated()->create();
    Cache::lock('geo-locate', 60)->get();

    $report = app(ComplexLocator::class)->locate();

    expect($report)->toBeNull();
    Http::assertNothingSent();
});
