<?php

use App\Models\Building;
use Illuminate\Support\Facades\DB;

it('derives its geography point from latitude and longitude', function () {
    $building = Building::factory()->create(['latitude' => 55.7558, 'longitude' => 37.6173]);

    $point = DB::scalar('select ST_AsText(geom) from buildings where id = ?', [$building->id]);

    expect($point)->toBe('POINT(37.6173 55.7558)');
});
