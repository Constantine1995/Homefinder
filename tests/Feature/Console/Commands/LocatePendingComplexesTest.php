<?php

use App\Models\Building;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('prints what the run geocoded and computed', function () {
    Sleep::fake();
    Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
        ->push([['lat' => '55.8542', 'lon' => '37.5931']])
        ->push([])]);
    Building::factory()->count(2)->unlocated()->create();

    $this->artisan('geo:locate')
        ->expectsTable(['Done', 'Count'], [
            ['Buildings geocoded', 1],
            ['Addresses not found', 1],
            ['Complexes computed', 2],
        ])
        ->assertSuccessful();
});

it('reports a geocoder failure', function () {
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response('Too many requests', 429)]);
    Building::factory()->unlocated()->create();

    $this->artisan('geo:locate')
        ->expectsOutputToContain('Geocoding stopped: The geocoder responded with HTTP 429.')
        ->assertFailed();
});
