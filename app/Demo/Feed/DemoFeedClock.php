<?php

namespace App\Demo\Feed;

use Illuminate\Container\Attributes\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * The day of the sales simulation the demo feed currently shows.
 *
 * Kept on the local disk rather than in the cache, so clearing the cache does not
 * roll the demo developer's sales back to day 0.
 */
class DemoFeedClock
{
    private const string PATH = 'demo-feed/day.txt';

    public function __construct(#[Storage('local')] private Filesystem $disk) {}

    /**
     * Get the day of the simulation the demo feed shows now.
     */
    public function today(): int
    {
        return (int) $this->disk->get(self::PATH);
    }

    /**
     * Move the simulation forward by the given number of days and get the new day.
     */
    public function advance(int $days): int
    {
        $day = $this->today() + $days;

        $this->disk->put(self::PATH, (string) $day);

        return $day;
    }

    /**
     * Move the simulation back to day 0.
     */
    public function reset(): void
    {
        $this->disk->delete(self::PATH);
    }
}
