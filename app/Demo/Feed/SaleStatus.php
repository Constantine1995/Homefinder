<?php

namespace App\Demo\Feed;

/**
 * Sales status of a flat in the demo developer's own system; only available flats get into the feed.
 */
enum SaleStatus
{
    /**
     * The developer has not put the flat on sale yet.
     */
    case Upcoming;

    case Available;
    case Reserved;
    case Sold;
}
