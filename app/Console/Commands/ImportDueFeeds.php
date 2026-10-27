<?php

namespace App\Console\Commands;

use App\Jobs\ImportFeed;
use App\Models\FeedSource;
use Cron\CronExpression;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('feeds:import-due')]
#[Description('Queue imports of the active feed sources whose schedule is due this minute')]
class ImportDueFeeds extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sources = FeedSource::query()
            ->where('is_active', true)
            ->whereNotNull('url')
            ->whereNotNull('schedule')
            ->get();

        foreach ($sources as $source) {
            if (! CronExpression::isValidExpression($source->schedule)) {
                $this->components->warn("Feed source {$source->id} has an invalid schedule «{$source->schedule}».");

                continue;
            }

            if ((new CronExpression($source->schedule))->isDue(now())) {
                ImportFeed::dispatch($source);
                $this->components->info("Queued an import of feed source {$source->id}.");
            }
        }

        return self::SUCCESS;
    }
}
