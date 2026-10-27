<?php

namespace App\Console\Commands;

use App\Services\Geo\ComplexLocator;
use App\Services\Geo\GeoException;
use App\Services\Geo\OsmExtract;
use App\Services\Geo\PoiImporter;
use App\Services\Geo\SurroundingsCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Signature('geo:import-pois
    {--file= : OpenStreetMap extract (.osm.pbf) to read instead of downloading the configured one}')]
#[Description('Import the points of interest of the region from OpenStreetMap and compute the surroundings of every complex anew')]
class ImportOsmPois extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OsmExtract $extract, PoiImporter $importer, SurroundingsCalculator $surroundings, ComplexLocator $locator): int
    {
        $file = $this->option('file');

        if ($file !== null && ! is_file($file)) {
            $this->components->error("There is no file {$file}.");

            return self::INVALID;
        }

        $directory = sys_get_temp_dir().'/osm-'.Str::uuid();
        File::ensureDirectoryExists($directory);

        try {
            $export = $extract->export($file ?? $extract->download($directory), $directory);
            $report = $importer->import($export);
        } catch (GeoException $exception) {
            $this->components->error("The import failed: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            File::deleteDirectory($directory);
        }

        $this->components->info('Points of interest imported.');
        $this->table(['Type', 'Count'], [
            ...array_map(fn (string $type, int $count): array => [$type, $count], array_keys($report['counts']), $report['counts']),
            ['removed', $report['removed']],
        ]);

        $surroundings->forgetAll();
        $located = $locator->locate();

        if ($located === null) {
            $this->components->warn('Complexes are being located right now; the next geo:locate computes their surroundings.');
        } else {
            $this->components->info("Surroundings of {$located['complexes']} complexes computed.");
        }

        return self::SUCCESS;
    }
}
