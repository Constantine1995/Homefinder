<?php

namespace App\Services\Feeds;

use App\Enums\Finishing;
use App\Enums\FlatStatus;

/**
 * A flat offered in a feed, translated into the attributes of our complex, building and flat.
 *
 * The external IDs identify the models within the feed source: the complex within the tenant,
 * the building within the complex and the flat within the source.
 */
readonly class FeedOffer
{
    /**
     * @param  array{external_id: string, name: string}  $complex
     * @param  array{external_id: string, name: string, address: ?string, latitude: ?float, longitude: ?float, floors: ?int, deadline: ?string}  $building
     * @param  array{external_id: string, number: ?string, floor: int, rooms: int, is_euro: bool, area: float, kitchen_area: ?float, finishing: ?Finishing, price: int, status: FlatStatus, plan_url: ?string, description: ?string, features: array<string, string|float|bool>}  $flat
     */
    public function __construct(
        public array $complex,
        public array $building,
        public array $flat,
    ) {}
}
