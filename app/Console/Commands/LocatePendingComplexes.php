<?php

namespace App\Console\Commands;

use App\Services\Geo\ComplexLocator;
use App\Services\Geo\GeoException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('geo:locate')]
#[Description('Geocode the buildings without coordinates and compute the surroundings of the complexes that need it')]
class LocatePendingComplexes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ComplexLocator $locator): int
    {
        try {
            $located = $locator->locate();
        } catch (GeoException $exception) {
            $this->components->error("Geocoding stopped: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($located === null) {
            $this->components->warn('Complexes are being located by another run.');

            return self::SUCCESS;
        }

        $this->table(['Done', 'Count'], [
            ['Buildings geocoded', $located['geocoded']],
            ['Addresses not found', $located['not_found']],
            ['Complexes computed', $located['complexes']],
        ]);

        return self::SUCCESS;
    }
}
