<?php

namespace App\Demo\Feed;

/**
 * Number of flats that changed in each way between two days of the simulation.
 */
readonly class FeedChanges
{
    /**
     * @param  int  $released  Flats put on sale for the first time.
     * @param  int  $returned  Flats back on sale after a reservation fell through.
     * @param  int  $cheaper  Listed flats whose price went down.
     * @param  int  $pricier  Listed flats whose price went up.
     * @param  int  $listed  Flats in the feed on the later day.
     */
    public function __construct(
        public int $released,
        public int $returned,
        public int $reserved,
        public int $sold,
        public int $cheaper,
        public int $pricier,
        public int $listed,
    ) {}
}
