<?php

namespace App\Jobs;

use App\Models\FeedSource;
use App\Services\Feeds\FeedImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;

/**
 * Imports a feed source; a failed import is not retried, the next scheduled import tries again.
 */
#[Tries(1)]
#[DeleteWhenMissingModels]
class ImportFeed implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public FeedSource $feedSource) {}

    /**
     * Execute the job.
     */
    public function handle(FeedImporter $importer): void
    {
        $importer->import($this->feedSource);
    }
}
