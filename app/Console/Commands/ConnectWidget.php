<?php

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('widget:connect {--tenant= : ID of the developer} {--policy-url= : Link to the developer\'s privacy policy; the demo policy by default}')]
#[Description("Connect the chat widget for a developer's site and print the snippet to embed; connecting it again keeps the key and updates the policy")]
class ConnectWidget extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenant = Tenant::query()->find($this->option('tenant'));

        if ($tenant === null) {
            $this->components->error('Give the ID of an existing developer with --tenant.');

            return self::FAILURE;
        }

        $channel = $tenant->run(function (): Channel {
            $channel = Channel::query()->where('type', ChannelType::Widget)->oldest('id')->first()
                ?? new Channel(['type' => ChannelType::Widget, 'external_id' => Str::random(32), 'credentials' => []]);

            $channel->fill([
                'name' => 'Виджет на сайте',
                'settings' => ['policy_url' => $this->option('policy-url') ?? $channel->settings['policy_url'] ?? route('demo.privacy')],
                'is_active' => true,
            ])->save();

            return $channel;
        });

        $this->components->info("The widget is channel {$channel->id} of {$tenant->name}. Put this before </body> on the site:");
        $this->line($this->snippet($channel));

        return self::SUCCESS;
    }

    /**
     * The tag that loads the widget on a site.
     */
    private function snippet(Channel $channel): string
    {
        return '<script src="'.asset('widget/widget.js').'" data-key="'.$channel->external_id.'" async></script>';
    }
}
