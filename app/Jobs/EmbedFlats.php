<?php

namespace App\Jobs;

use App\Services\Agent\AgentException;
use App\Services\Search\FlatEmbedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;

/**
 * Computes the vectors of the current tenant's new and changed flats after an import.
 *
 * The import must not depend on the agent service, so a service that is down only leaves a warning:
 * the hourly `search:embed` picks up what is left.
 */
#[Tries(1)]
class EmbedFlats implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(FlatEmbedder $embedder): void
    {
        try {
            $embedder->embedPending();
        } catch (AgentException $exception) {
            Log::warning('The flats were not embedded after the import.', ['exception' => $exception]);
        }
    }
}
