<?php

use App\Services\Geo\GeoException;
use App\Services\Geo\NominatimGeocoder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('finds the coordinates of an address within the region, introducing itself to the server', function () {
    config(['geo.nominatim.email' => 'dev@homefinder.test']);
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '55.8539785', 'lon' => '37.5935363']])]);

    $point = app(NominatimGeocoder::class)->geocode('Москва, Сигнальный проезд, 19');

    expect($point)->toBe(['latitude' => 55.8539785, 'longitude' => 37.5935363]);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search?')
        && $request->header('User-Agent') === ['homefinder/1.0 (pet project)']
        && $request['q'] === 'Москва, Сигнальный проезд, 19'
        && $request['viewbox'] === '37.322,55.566,37.881,55.916'
        && $request['bounded'] === 1
        && $request['email'] === 'dev@homefinder.test');
});

it('finds nothing for an address outside the region', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);

    $point = app(NominatimGeocoder::class)->geocode('Новосибирск, Красный проспект, 1');

    expect($point)->toBeNull();
});

it('waits a second between requests, as the public server asks', function () {
    Sleep::fake();
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([])]);
    $geocoder = app(NominatimGeocoder::class);

    $geocoder->geocode('Сигнальный проезд, 19');
    $geocoder->geocode('Крылатская улица, 2');
    $geocoder->geocode('Мосрентген, квартал 21А');

    Sleep::assertSequence([Sleep::for(1)->second(), Sleep::for(1)->second()]);
});

it('fails when the server fails', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('Too many requests', 429)]);

    expect(fn () => app(NominatimGeocoder::class)->geocode('Сигнальный проезд, 19'))
        ->toThrow(GeoException::class, 'The geocoder responded with HTTP 429.');
});
