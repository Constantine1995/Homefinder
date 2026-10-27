<?php

namespace App\Services\Feeds;

use RuntimeException;

/**
 * Thrown while reading an offer that lacks something a flat needs or carries a malformed value.
 */
class InvalidOfferException extends RuntimeException {}
