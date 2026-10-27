<?php

namespace App\Services\Feeds;

use RuntimeException;

/**
 * A problem with the feed itself rather than with the application: the feed is unreachable,
 * is not a feed of the source's format, is malformed or has no valid offers.
 *
 * The import fails with the exception's message in its report, and the catalog stays as it was.
 */
class FeedException extends RuntimeException {}
