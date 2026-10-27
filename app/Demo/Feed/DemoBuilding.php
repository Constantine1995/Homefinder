<?php

namespace App\Demo\Feed;

/**
 * A building (корпус) of a demo residential complex.
 */
readonly class DemoBuilding
{
    /**
     * @param  string  $yandexHouseId  Building ID in the Yandex database of new buildings; made up for the demo.
     * @param  int  $readyQuarter  Quarter of the year the building is completed, 1 to 4.
     * @param  string  $state  Construction stage as Yandex names it: "unfinished", "built" or "hand-over".
     * @param  string  $renovation  Finishing as Yandex names it, e.g. "чистовая" or "whitebox".
     * @param  string  $buildingType  Building type as Yandex names it: "монолит", "панельный" or "кирпичный".
     * @param  string  $salesStart  Date the flats of the building go on sale, possibly after the simulation starts.
     * @param  float  $soldShare  Share of the flats sold before the simulation starts.
     * @param  list<FloorPosition>  $floorPlate  Flats of every residential floor; the ground floor has none.
     */
    public function __construct(
        public int $number,
        public string $yandexHouseId,
        public string $address,
        public float $latitude,
        public float $longitude,
        public int $floors,
        public int $readyYear,
        public int $readyQuarter,
        public string $state,
        public string $renovation,
        public string $buildingType,
        public float $ceilingHeight,
        public string $salesStart,
        public float $soldShare,
        public array $floorPlate,
    ) {}

    /**
     * Get the building's name within the complex, e.g. "корпус 2".
     */
    public function section(): string
    {
        return 'корпус '.$this->number;
    }
}
