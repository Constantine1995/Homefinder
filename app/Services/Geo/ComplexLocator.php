<?php

namespace App\Services\Geo;

use App\Models\Building;
use App\Models\Complex;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\Cache;

/**
 * Puts complexes on the map: geocodes the buildings that came with an address but without coordinates,
 * places their complexes and computes the surroundings of every complex that needs it.
 *
 * Works for the current tenant, or for all of them without one. Only one run goes at a time.
 */
class ComplexLocator
{
    /**
     * Longest time a run holds the lock; geocoding is the slow part, at a request a second.
     */
    private const int LOCK_SECONDS = 600;

    public function __construct(
        private NominatimGeocoder $geocoder,
        private SurroundingsCalculator $surroundings,
        #[Config('geo.nominatim.addresses_per_run')] private int $addressesPerRun,
    ) {}

    /**
     * @return array{geocoded: int, not_found: int, complexes: int}|null What the run did, or null if another run is going.
     *
     * @throws GeoException when the geocoder fails; the buildings geocoded before that keep their coordinates
     */
    public function locate(): ?array
    {
        $lock = Cache::lock('geo-locate', self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            [$geocodedCount, $notFoundCount] = $this->geocodeBuildings();

            return [
                'geocoded' => $geocodedCount,
                'not_found' => $notFoundCount,
                'complexes' => $this->surroundings->computePending(),
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Geocode a batch of the buildings not tried since their address last changed; the rest wait for the next run.
     *
     * @return array{int, int} The numbers of buildings geocoded and not found.
     */
    private function geocodeBuildings(): array
    {
        $buildings = Building::query()
            ->whereNotNull('address')
            ->whereNull('latitude')
            ->whereNull('geocoded_at')
            ->orderBy('id')
            ->limit($this->addressesPerRun)
            ->get();
        $geocodedBuildings = collect();
        $notFoundCount = 0;

        try {
            foreach ($buildings as $building) {
                $point = $this->geocoder->geocode($building->address);
                $building->forceFill([...$point ?? [], 'geocoded_at' => now()])->save();

                if ($point === null) {
                    $notFoundCount++;
                } else {
                    $geocodedBuildings->push($building);
                }
            }
        } finally {
            Complex::whereKey($geocodedBuildings->pluck('complex_id')->unique())
                ->get()
                ->each(fn (Complex $complex) => $complex->placeAtBuildings());
        }

        return [$geocodedBuildings->count(), $notFoundCount];
    }
}
