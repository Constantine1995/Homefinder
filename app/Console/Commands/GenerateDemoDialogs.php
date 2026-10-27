<?php

namespace App\Console\Commands;

use App\Demo\Dialogs\DemoDialogs;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo-dialogs:generate {--tenant=1 : The demo developer} {--count=240 : Dialogs to write} {--days=30 : Over how many past days} {--seed=1 : The same seed writes the same dialogs}')]
#[Description('Write a month of the demo developer\'s Telegram dialogs for the statistics and the shared chat, replacing the generated ones')]
class GenerateDemoDialogs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DemoDialogs $dialogs): int
    {
        $tenant = Tenant::query()->findOrFail($this->option('tenant'));
        $count = $dialogs->generate($tenant, (int) $this->option('count'), (int) $this->option('days'), (int) $this->option('seed'));

        $this->components->info("Wrote {$count} dialogs of {$tenant->name} over the last {$this->option('days')} days.");

        return self::SUCCESS;
    }
}
