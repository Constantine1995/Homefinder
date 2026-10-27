<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\Channels\Telegram\TelegramClient;
use App\Services\Channels\Telegram\TelegramException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:webhook {channel : ID of the Telegram channel} {--delete : Stop the webhook, to poll instead}')]
#[Description("Have Telegram post the bot's updates to this application; Telegram needs a public HTTPS address")]
class SetTelegramWebhook extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(TelegramClient $telegram): int
    {
        $channel = Channel::query()->find($this->argument('channel'));

        if ($channel === null) {
            $this->components->error('No such channel.');

            return self::FAILURE;
        }

        $bot = $telegram->bot($channel->token());
        $url = route('webhooks.telegram', $channel);

        try {
            if ($this->option('delete')) {
                $bot->deleteWebhook();
                $this->components->info("Telegram no longer posts to the webhook of {$channel->name}.");

                return self::SUCCESS;
            }

            $bot->setWebhook($url, $channel->webhookSecret());
        } catch (TelegramException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Telegram posts the updates of {$channel->name} to {$url}.");

        return self::SUCCESS;
    }
}
