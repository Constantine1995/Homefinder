<?php

use App\Enums\PoiType;
use App\Models\Complex;
use App\Models\Poi;
use App\Services\Geo\SurroundingsCalculator;

/**
 * Create a point of interest at the point, given as latitude and longitude.
 */
function poiAt(PoiType $type, float $latitude, float $longitude): Poi
{
    return Poi::factory()->create(['type' => $type, 'geom' => sprintf('SRID=4326;POINT(%F %F)', $longitude, $latitude)]);
}

/**
 * Create a park over the square whose south-west corner is at the point and whose side is given in degrees.
 */
function parkOver(float $latitude, float $longitude, float $side): Poi
{
    return Poi::factory()->create([
        'type' => PoiType::Park,
        'geom' => sprintf(
            'SRID=4326;MULTIPOLYGON(((%2$F %1$F, %3$F %1$F, %3$F %4$F, %2$F %4$F, %2$F %1$F)))',
            $latitude, $longitude, $longitude + $side, $latitude + $side,
        ),
    ]);
}

it('links a complex with the metro within 3 km and the other places within 1 km, with the walk to each', function () {
    $complex = Complex::factory()->create(['latitude' => 55.8542, 'longitude' => 37.5931]);
    $nearSchool = poiAt(PoiType::School, 55.8587, 37.5931);
    poiAt(PoiType::School, 55.8677, 37.5931);
    $metro = poiAt(PoiType::Metro, 55.8722, 37.5931);
    poiAt(PoiType::Metro, 55.8822, 37.5931);
    $park = parkOver(55.8562, 37.5906, 0.005);
    parkOver(55.8552, 37.5931, 0.0005);

    $computedCount = app(SurroundingsCalculator::class)->computePending();

    expect($computedCount)->toBe(1);
    expect($complex->pois()->orderBy('distance_m')->get()->map(fn (Poi $poi): array => [
        $poi->id, $poi->pivot->distance_m, $poi->pivot->walk_min,
    ])->all())->toBe([
        [$park->id, 223, 4],
        [$nearSchool->id, 501, 9],
        [$metro->id, 2004, 33],
    ]);
    expect($complex->fresh()->pois_computed_at)->not->toBeNull();
});

it('counts a minute of walking to the park the complex stands in', function () {
    $complex = Complex::factory()->create(['latitude' => 55.8542, 'longitude' => 37.5931]);
    parkOver(55.852, 37.59, 0.005);

    app(SurroundingsCalculator::class)->computePending();

    expect($complex->pois()->sole()->pivot)
        ->distance_m->toBe(0)
        ->walk_min->toBe(1);
});

it('computes only the complexes that are new, moved or forgotten', function () {
    $this->travelTo('2026-10-20 10:00:00');
    poiAt(PoiType::School, 55.8587, 37.5931);
    $computedComplex = Complex::factory()->create(['latitude' => 55.8542, 'longitude' => 37.5931]);
    $movedComplex = Complex::factory()->create(['latitude' => 55.75, 'longitude' => 37.43]);
    app(SurroundingsCalculator::class)->computePending();
    $movedComplex->update(['latitude' => 55.8542, 'longitude' => 37.5931]);
    $this->travelTo('2026-10-20 11:00:00');

    $computedCount = app(SurroundingsCalculator::class)->computePending();

    expect($computedCount)->toBe(1)
        ->and($movedComplex->pois()->count())->toBe(1)
        ->and($movedComplex->fresh()->pois_computed_at->toDateTimeString())->toBe('2026-10-20 11:00:00')
        ->and($computedComplex->fresh()->pois_computed_at->toDateTimeString())->toBe('2026-10-20 10:00:00');
});

it('leaves a complex that lost its coordinates without surroundings', function () {
    poiAt(PoiType::School, 55.8587, 37.5931);
    $complex = Complex::factory()->create(['latitude' => 55.8542, 'longitude' => 37.5931]);
    app(SurroundingsCalculator::class)->computePending();
    $complex->update(['latitude' => null, 'longitude' => null]);

    app(SurroundingsCalculator::class)->computePending();

    expect($complex->pois()->count())->toBe(0);
});

it('forgets the surroundings of the complexes of every tenant', function () {
    $complexes = Complex::factory()->count(2)->create();
    app(SurroundingsCalculator::class)->computePending();
    $complexes->first()->tenant->makeCurrent();

    app(SurroundingsCalculator::class)->forgetAll();

    expect(Complex::withoutGlobalScopes()->whereNull('pois_computed_at')->count())->toBe(2);
});
