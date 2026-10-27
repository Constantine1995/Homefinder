<?php

use App\Services\Geo\GeoException;
use App\Services\Geo\OsmExtract;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('filters the extract down to the points of interest with osmium and exports them as GeoJSON', function () {
    Process::fake();

    $exportPath = app(OsmExtract::class)->export('/data/moscow.osm.pbf', '/tmp/osm');

    expect($exportPath)->toBe('/tmp/osm/pois.geojsonseq');
    Process::assertRanInOrder([
        fn (PendingProcess $process): bool => $process->command === [
            'osmium', 'tags-filter', '--overwrite', '--output', '/tmp/osm/pois.osm.pbf', '/data/moscow.osm.pbf',
            'n/railway=station', 'nwr/amenity=school,kindergarten', 'nwr/leisure=park', 'nwr/shop=supermarket,convenience,mall',
        ],
        fn (PendingProcess $process): bool => $process->command === [
            'osmium', 'export', '--overwrite', '--output', '/tmp/osm/pois.geojsonseq', '--output-format', 'geojsonseq',
            '--geometry-types', 'point,polygon', '--add-unique-id', 'type_id', '/tmp/osm/pois.osm.pbf',
        ],
    ]);
});

it('fails with what osmium said when it fails', function () {
    Process::fake(['*' => Process::result(errorOutput: 'Unknown file type', exitCode: 1)]);

    expect(fn () => app(OsmExtract::class)->export('/data/feed.xml', '/tmp/osm'))
        ->toThrow(GeoException::class, 'osmium tags-filter failed: Unknown file type');
});

it('downloads the extract of the region into a file', function () {
    Http::preventStrayRequests();
    Http::fake(['download.bbbike.org/*' => Http::response('pbf bytes')]);
    $directory = dirname(test()->temporaryPath('pbf'));

    $path = app(OsmExtract::class)->download($directory);

    expect(file_get_contents($path))->toBe('pbf bytes');
    unlink($path);
});

it('fails when the extract cannot be downloaded', function () {
    Http::fake(['download.bbbike.org/*' => Http::response('Not found', 404)]);

    expect(fn () => app(OsmExtract::class)->download(sys_get_temp_dir()))
        ->toThrow(GeoException::class, 'The OpenStreetMap extract server responded with HTTP 404.');
});
