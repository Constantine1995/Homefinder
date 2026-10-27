<?php

namespace App\Services\Feeds;

/**
 * An offer of a feed that cannot be imported; the rest of the feed is imported without it.
 */
readonly class InvalidOffer
{
    /**
     * @param  string|null  $externalId  The flat's ID in the feed, if the offer has one.
     */
    public function __construct(
        public ?string $externalId,
        public string $reason,
    ) {}
}
