<?php

namespace App\Console\Commands;

use App\Jobs\HandleTelegramUpdate;
use App\Models\Channel;
use App\Services\Channels\Telegram\TelegramClient;
use App\Services\Channels\Telegram\TelegramException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

#[Signature('telegram:poll {channel : ID of the Telegram channel}')]
#[Description("Fetch the bot's updates from Telegram until stopped, for development without a public HTTPS address")]
class PollTelegramUpdates extends Command
{
    /**
     * How long Telegram holds a request when there are no updates.
     */
    public const int WAIT_SECONDS = 30;

    private bool $polling = true;

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
        $this->trap([SIGINT, SIGTERM], function (): void {
            $this->polling = false;
        });

        try {
            // Telegram gives no updates to polling while a webhook is set.
            $bot->deleteWebhook();
        } catch (TelegramException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Polling the updates of {$channel->name}; stop with Ctrl+C.");
        $offset = null;

        while ($this->polling) {
            try {
                $updates = $bot->getUpdates($offset, self::WAIT_SECONDS);
            } catch (TelegramException $exception) {
                $this->components->warn($exception->getMessage());
                Sleep::for($exception->retryAfter ?? 5)->seconds();

                continue;
            }

            foreach ($updates as $update) {
                HandleTelegramUpdate::dispatch($channel, $update);
                $offset = $update['update_id'] + 1;
            }
        }

        return self::SUCCESS;
    }
}
