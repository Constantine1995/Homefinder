<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Search\FlatEmbedder;
use App\Services\Search\KnowledgeEmbedder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search:embed')]
#[Description('Compute the vectors of the flats and knowledge base chunks without one')]
class EmbedPendingFlats extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FlatEmbedder $flats, KnowledgeEmbedder $knowledge): int
    {
        try {
            $embeddedFlats = $flats->embedPending();
            $embeddedChunks = $knowledge->embedPending();
        } catch (AgentException $exception) {
            $this->components->error("Embedding stopped: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Flats embedded: %s. Knowledge chunks embedded: %s.',
            $embeddedFlats ?? 'another run is going',
            $embeddedChunks ?? 'another run is going',
        ));

        return self::SUCCESS;
    }
}
