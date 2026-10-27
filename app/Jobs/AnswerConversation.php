<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Dialogs\ConversationResponder;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Answers a conversation's latest buyer message with the AI agent, on the `agent` queue: a turn of a model on a
 * laptop takes up to the agent service's timeout.
 *
 * One turn of a conversation at a time; a message sent during a turn is answered by the next job. A buyer writing
 * from a channel gets the reply there.
 */
#[Queue('agent')]
class AnswerConversation implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds the job may run: longer than the agent service's timeout, which is long for a model on a laptop.
     */
    public int $timeout;

    public function __construct(public Conversation $conversation)
    {
        $this->timeout = config('services.agent.timeout') + 30;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->conversation->id))->releaseAfter(5)->expireAfter($this->timeout + 30)];
    }

    /**
     * Keep releasing the job while another turn of the conversation runs, for as long as a turn can last.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * Execute the job.
     */
    public function handle(ConversationResponder $responder): void
    {
        $reply = $responder->respond($this->conversation);

        if ($reply !== null && $this->conversation->contact_id !== null) {
            DeliverMessage::dispatch($reply);
        }
    }
}
