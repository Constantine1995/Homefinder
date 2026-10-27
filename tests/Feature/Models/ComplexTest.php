<?php

use App\Models\Building;
use App\Models\Complex;
use Illuminate\Support\Facades\DB;

it('derives its geography point from latitude and longitude', function () {
    $complex = Complex::factory()->create(['latitude' => 55.7558, 'longitude' => 37.6173]);

    $point = DB::scalar('select ST_AsText(geom) from complexes where id = ?', [$complex->id]);

    expect($point)->toBe('POINT(37.6173 55.7558)');
});

it('takes the address of its first building, the coordinates of the first located one and the deadline of the last', function () {
    $complex = Complex::factory()->create();
    Building::factory()->for($complex)->unlocated()->create(['external_id' => '1', 'address' => 'Сигнальный проезд, 19к1', 'deadline' => '2026-12-31']);
    Building::factory()->for($complex)->create(['external_id' => '2', 'latitude' => 55.8537, 'longitude' => 37.594, 'deadline' => '2027-12-31']);

    $complex->placeAtBuildings();

    expect($complex->fresh())
        ->address->toBe('Сигнальный проезд, 19к1')
        ->latitude->toBe(55.8537)
        ->longitude->toBe(37.594)
        ->deadline->toDateString()->toBe('2027-12-31');
});
