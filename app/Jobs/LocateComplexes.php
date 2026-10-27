<?php

namespace App\Jobs;

use App\Services\Geo\ComplexLocator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;

/**
 * Puts the complexes of the current tenant on the map after an import; a failed run is not retried,
 * the hourly `geo:locate` picks up what is left.
 */
#[Tries(1)]
class LocateComplexes implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(ComplexLocator $locator): void
    {
        $locator->locate();
    }
}
