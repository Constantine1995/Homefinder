<?php

use App\Enums\PoiType;
use App\Models\Complex;
use App\Models\Poi;
use Illuminate\Console\Command;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Fake osmium so that its export holds the features.
 */
function osmiumExporting(string ...$features): void
{
    Process::fake(function (PendingProcess $process) use ($features) {
        if ($process->command[1] === 'export') {
            file_put_contents($process->command[4], implode('', $features));
        }

        return Process::result();
    });
}

it('imports the points of interest of the downloaded extract and computes the surroundings of the complexes', function () {
    Http::preventStrayRequests();
    Http::fake(['download.bbbike.org/*' => Http::response('pbf bytes')]);
    osmiumExporting(
        osmFeature('n101', osmPoint(55.8587, 37.5931), ['railway' => 'station', 'station' => 'subway', 'name' => 'Владыкино']),
        osmFeature('n105', osmPoint(55.8545, 37.5931), ['shop' => 'supermarket']),
    );
    $complex = Complex::factory()->create(['latitude' => 55.8542, 'longitude' => 37.5931]);

    $this->artisan('geo:import-pois')
        ->expectsOutputToContain('Points of interest imported.')
        ->expectsTable(['Type', 'Count'], [
            ['metro', 1],
            ['school', 0],
            ['kindergarten', 0],
            ['park', 0],
            ['shop', 1],
            ['removed', 0],
        ])
        ->expectsOutputToContain('Surroundings of 1 complexes computed.')
        ->assertSuccessful();

    expect($complex->pois()->orderBy('pois.type')->pluck('type')->all())->toBe([PoiType::Metro, PoiType::Shop]);
});

it('reads an extract from a file', function () {
    osmiumExporting(osmFeature('n101', osmPoint(55.8587, 37.5931), ['railway' => 'station', 'station' => 'subway']));
    $extractPath = test()->temporaryPath('osm.pbf');
    file_put_contents($extractPath, 'pbf bytes');

    $this->artisan('geo:import-pois', ['--file' => $extractPath])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command[1] === 'tags-filter' && $process->command[5] === $extractPath);
    expect(Poi::count())->toBe(1);
});

it('reports why the import failed and keeps the points of interest', function () {
    $poi = Poi::factory()->create();
    Process::fake(['*' => Process::result(errorOutput: 'Unknown file type', exitCode: 1)]);
    $extractPath = test()->temporaryPath('osm.pbf');
    file_put_contents($extractPath, 'not an extract');

    $this->artisan('geo:import-pois', ['--file' => $extractPath])
        ->expectsOutputToContain('The import failed: osmium tags-filter failed: Unknown file type')
        ->assertFailed();

    $this->assertModelExists($poi);
});

it('rejects an extract file that does not exist', function () {
    Process::fake();

    $this->artisan('geo:import-pois', ['--file' => '/no/such/moscow.osm.pbf'])
        ->expectsOutputToContain('There is no file /no/such/moscow.osm.pbf.')
        ->assertExitCode(Command::INVALID);

    Process::assertNothingRan();
});
