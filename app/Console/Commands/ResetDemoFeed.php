<?php

namespace App\Console\Commands;

use App\Demo\Feed\DemoFeedClock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo-feed:reset')]
#[Description('Move the demo developer\'s sales back to the first day')]
class ResetDemoFeed extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DemoFeedClock $clock): int
    {
        $clock->reset();

        $this->components->info('The demo feed is back at day 0.');

        return self::SUCCESS;
    }
}
