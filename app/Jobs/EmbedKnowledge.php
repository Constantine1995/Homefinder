<?php

namespace App\Jobs;

use App\Services\Agent\AgentException;
use App\Services\Search\KnowledgeEmbedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;

/**
 * Computes the vectors of the current tenant's new knowledge base chunks after an import. A service that is
 * down only leaves a warning: the hourly `search:embed` picks up what is left.
 */
#[Tries(1)]
class EmbedKnowledge implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(KnowledgeEmbedder $embedder): void
    {
        try {
            $embedder->embedPending();
        } catch (AgentException $exception) {
            Log::warning('The knowledge base was not embedded after the import.', ['exception' => $exception]);
        }
    }
}
