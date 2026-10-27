<?php

namespace Database\Factories;

use App\Enums\OsmType;
use App\Enums\PoiType;
use App\Models\Poi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Poi>
 */
class PoiFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'osm_type' => OsmType::Node,
            'osm_id' => fake()->unique()->numberBetween(1, 10_000_000_000),
            'type' => fake()->randomElement(PoiType::cases()),
            'name' => fake()->streetName(),
            'geom' => sprintf('SRID=4326;POINT(%F %F)', fake()->longitude(37.4, 37.8), fake()->latitude(55.6, 55.9)),
        ];
    }
}
