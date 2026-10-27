<?php

namespace App\Services\Feeds;

use Generator;

/**
 * Reads the flats of a file in one source format, such as a YRL feed or an availability spreadsheet.
 */
interface FeedReader
{
    /**
     * Read the offers of the file, keyed by where they are in it: the offer's number in a feed,
     * the row's number in a spreadsheet.
     *
     * @return Generator<int, FeedOffer|InvalidOffer>
     *
     * @throws FeedException when the file is not of the reader's format or is broken
     */
    public function read(string $path): Generator;
}
