<?php

use App\Enums\OsmType;
use App\Enums\PoiType;
use App\Models\Complex;
use App\Models\Poi;
use App\Services\Geo\GeoException;
use App\Services\Geo\PoiImporter;
use Illuminate\Support\Facades\DB;

it('imports the points of interest of every type and skips the other objects of the export', function () {
    $path = osmExportFile(
        osmFeature('n101', osmPoint(55.8472, 37.5899), ['railway' => 'station', 'station' => 'subway', 'name' => 'Владыкино']),
        osmFeature('n102', osmPoint(55.8473, 37.5963), ['railway' => 'station', 'train' => 'yes', 'name' => 'Владыкино-Московское']),
        osmFeature('a204', osmSquare(55.85, 37.59, 0.001), ['amenity' => 'school', 'name' => 'Школа № 962']),
        osmFeature('a205', osmSquare(55.86, 37.59, 0.001), ['amenity' => 'kindergarten']),
        osmFeature('w103', osmSquare(55.87, 37.59, 0.01), ['leisure' => 'park', 'name' => 'Парк «Отрада»']),
        osmFeature('n104', osmPoint(55.88, 37.59), ['leisure' => 'park', 'name' => 'Сквер без контура']),
        osmFeature('n105', osmPoint(55.85, 37.60), ['shop' => 'supermarket', 'name' => 'Ашан']),
        osmFeature('n106', osmPoint(55.85, 37.61), ['shop' => 'clothes']),
        osmFeature('n107', osmPoint(55.85, 37.62), ['amenity' => 'cafe']),
        osmFeature('n108', osmPoint(55.85, 37.63), []),
    );

    $report = app(PoiImporter::class)->import($path);

    expect($report)->toBe([
        'counts' => ['metro' => 1, 'school' => 1, 'kindergarten' => 1, 'park' => 1, 'shop' => 1],
        'removed' => 0,
    ]);
    expect(Poi::orderBy('osm_id')->orderBy('osm_type')->get()->map(fn (Poi $poi): array => [$poi->osm_type, $poi->osm_id, $poi->type, $poi->name])->all())->toBe([
        [OsmType::Node, 101, PoiType::Metro, 'Владыкино'],
        [OsmType::Relation, 102, PoiType::Kindergarten, null],
        [OsmType::Way, 102, PoiType::School, 'Школа № 962'],
        [OsmType::Way, 103, PoiType::Park, 'Парк «Отрада»'],
        [OsmType::Node, 105, PoiType::Shop, 'Ашан'],
    ]);
    expect(Poi::firstWhere('osm_id', 101)->tags)->toEqual(['railway' => 'station', 'station' => 'subway', 'name' => 'Владыкино']);
    expect(DB::scalar('select ST_AsText(geom) from pois where osm_id = 101'))->toBe('POINT(37.5899 55.8472)');
});

it('updates the points of interest still in the data and deletes the rest with their distances', function () {
    app(PoiImporter::class)->import(osmExportFile(
        osmFeature('n101', osmPoint(55.8472, 37.5899), ['railway' => 'station', 'station' => 'subway', 'name' => 'Владыкино']),
        osmFeature('n105', osmPoint(55.85, 37.60), ['shop' => 'supermarket', 'name' => 'Ашан']),
    ));
    $closedShop = Poi::firstWhere('osm_id', 105);
    $complex = Complex::factory()->create();
    $complex->pois()->attach($closedShop, ['tenant_id' => $complex->tenant_id, 'distance_m' => 300, 'walk_min' => 5]);
    $this->travel(1)->minute();

    $report = app(PoiImporter::class)->import(osmExportFile(
        osmFeature('n101', osmPoint(55.8472, 37.5899), ['railway' => 'station', 'station' => 'subway', 'name' => 'Владыкино (МЦК)']),
    ));

    expect($report['removed'])->toBe(1)
        ->and(Poi::pluck('name')->all())->toBe(['Владыкино (МЦК)']);
    $this->assertModelMissing($closedShop);
    $this->assertDatabaseCount('complex_poi', 0);
});

it('fails without deleting anything when the export has no points of interest', function () {
    app(PoiImporter::class)->import(osmExportFile(
        osmFeature('n101', osmPoint(55.8472, 37.5899), ['railway' => 'station', 'station' => 'subway']),
    ));
    $this->travel(1)->minute();

    expect(fn () => app(PoiImporter::class)->import(osmExportFile(osmFeature('n108', osmPoint(55.85, 37.63), []))))
        ->toThrow(GeoException::class, 'The OpenStreetMap export has no points of interest.');
    expect(Poi::count())->toBe(1);
});
