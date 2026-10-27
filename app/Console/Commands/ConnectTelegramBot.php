<?php

namespace App\Console\Commands;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Channels\Telegram\TelegramClient;
use App\Services\Channels\Telegram\TelegramException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('telegram:connect {token : The bot token @BotFather gave} {--tenant= : ID of the developer} {--policy-url= : Link to the developer\'s privacy policy; the demo policy by default}')]
#[Description("Connect a developer's Telegram bot as a channel; connecting it again updates the token and the policy")]
class ConnectTelegramBot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TelegramClient $telegram): int
    {
        $tenant = Tenant::query()->find($this->option('tenant'));

        if ($tenant === null) {
            $this->components->error('Give the ID of an existing developer with --tenant.');

            return self::FAILURE;
        }

        try {
            $bot = $telegram->bot($this->argument('token'))->getMe();
        } catch (TelegramException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $channel = Channel::withoutGlobalScope(TenantScope::class)
            ->where('type', ChannelType::Telegram)
            ->where('external_id', (string) $bot['id'])
            ->first();

        if ($channel !== null && $channel->tenant_id !== $tenant->id) {
            $this->components->error("The bot @{$bot['username']} is connected for another developer.");

            return self::FAILURE;
        }

        $channel ??= new Channel(['type' => ChannelType::Telegram, 'external_id' => (string) $bot['id']]);
        $channel->tenant_id = $tenant->id;
        $channel->fill([
            'name' => "@{$bot['username']}",
            'credentials' => [
                'token' => $this->argument('token'),
                'webhook_secret' => $channel->credentials['webhook_secret'] ?? Str::random(48),
            ],
            'settings' => ['policy_url' => $this->option('policy-url') ?? route('demo.privacy')],
            'is_active' => true,
        ])->save();

        $this->components->info("The bot @{$bot['username']} is channel {$channel->id} of {$tenant->name}.");
        $this->components->bulletList([
            "On a server with HTTPS: php artisan telegram:webhook {$channel->id}",
            "Locally, without a public address: php artisan telegram:poll {$channel->id}",
        ]);

        return self::SUCCESS;
    }
}
