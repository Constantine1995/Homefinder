<?php

namespace App\Jobs;

use App\Enums\ConversationChannel;
use App\Events\WidgetMessageSent;
use App\Models\Message;
use App\Services\Channels\Telegram\TelegramException;
use App\Services\Channels\Telegram\TelegramMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;

/**
 * Sends a message of the sales side to the buyer's channel: to Telegram, or a ping to the open site widget, which
 * fetches the message itself (and marks it delivered); the test chat shows messages itself.
 */
#[Tries(5)]
#[Backoff([5, 30, 120, 300])]
class DeliverMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public Message $message) {}

    /**
     * Execute the job.
     */
    public function handle(TelegramMessenger $telegram): void
    {
        $this->message->tenant->run(function () use ($telegram): void {
            $conversation = $this->message->conversation;

            if ($conversation->channel === ConversationChannel::Widget) {
                WidgetMessageSent::dispatch($conversation->contact_id, $this->message->id);

                return;
            }

            if ($conversation->channel !== ConversationChannel::Telegram) {
                return;
            }

            try {
                $telegram->deliver($this->message);
            } catch (TelegramException $exception) {
                if ($exception->isBlocked()) {
                    Log::info('The buyer blocked the bot; the message stays undelivered.', ['message_id' => $this->message->id]);

                    return;
                }

                if ($exception->retryAfter !== null) {
                    $this->release($exception->retryAfter);

                    return;
                }

                throw $exception;
            }
        });
    }
}
