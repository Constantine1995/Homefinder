<?php

namespace App\Demo\Feed;

/**
 * A made-up residential complex of the demo developer, placed at a real Moscow address.
 */
readonly class DemoComplex
{
    /**
     * @param  string  $code  Prefix of the IDs of the complex's flats.
     * @param  string  $yandexBuildingId  Complex ID in the Yandex database of new buildings; made up for the demo.
     * @param  int  $pricePerSquareMeter  Price before the floor, view, finishing and deadline premiums, in rubles.
     * @param  float  $demand  How quickly the complex sells compared with an average one.
     * @param  string  $tagline  Sentence that closes the description of every flat.
     * @param  list<DemoBuilding>  $buildings
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $yandexBuildingId,
        public int $pricePerSquareMeter,
        public float $demand,
        public string $tagline,
        public array $buildings,
    ) {}
}
