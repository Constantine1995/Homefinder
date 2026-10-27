<?php

namespace App\Demo\Feed;

/**
 * A flat's sales status and price on one day of the simulation.
 */
readonly class FlatState
{
    /**
     * @param  int  $releaseDay  Day the flat was or will be put on sale; days before the simulation are negative.
     */
    public function __construct(
        public SaleStatus $status,
        public int $price,
        public int $releaseDay,
    ) {}

    /**
     * Determine whether the flat is on sale and therefore listed in the feed.
     */
    public function isListed(): bool
    {
        return $this->status === SaleStatus::Available;
    }
}
