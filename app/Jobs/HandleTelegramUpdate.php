<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\Channels\Telegram\TelegramUpdateHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

/**
 * Handles an update of a developer's Telegram bot off the webhook, which answers Telegram at once.
 */
#[Tries(3)]
#[Backoff([5, 30])]
class HandleTelegramUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $update
     */
    public function __construct(public Channel $channel, public array $update) {}

    /**
     * Execute the job.
     */
    public function handle(TelegramUpdateHandler $handler): void
    {
        $handler->handle($this->channel, $this->update);
    }
}
