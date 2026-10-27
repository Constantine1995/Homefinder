<?php

namespace App\Console\Commands;

use App\Demo\Feed\DemoFeedClock;
use App\Demo\Feed\SalesSimulation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo-feed:advance {days=1 : Number of days of sales to simulate}')]
#[Description('Move the demo developer\'s sales forward: flats get reserved, sold, released and discounted')]
class AdvanceDemoFeed extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DemoFeedClock $clock, SalesSimulation $simulation): int
    {
        $days = filter_var($this->argument('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($days === false) {
            $this->components->error('The number of days must be a positive integer.');

            return self::INVALID;
        }

        $fromDay = $clock->today();
        $toDay = $clock->advance($days);
        $changes = $simulation->changesBetween($fromDay, $toDay);

        $this->components->info("The demo feed moved from day {$fromDay} to day {$toDay}.");

        $this->table(['Change', 'Flats'], [
            ['Put on sale', $changes->released],
            ['Back on sale after a reservation', $changes->returned],
            ['Reserved', $changes->reserved],
            ['Sold', $changes->sold],
            ['Cheaper', $changes->cheaper],
            ['More expensive', $changes->pricier],
            ['Listed in the feed', $changes->listed],
        ]);

        return self::SUCCESS;
    }
}
