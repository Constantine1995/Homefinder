<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the developer's open cabinets that a conversation has changed: a new message, or another side answers now.
 *
 * Only the IDs go over the websocket; the page fetches what changed itself, so what an employee may see is decided
 * by the controller, as on any request. Sent after the transaction commits, so the page finds the change, and at
 * once, not from the queue busy with the agent's turns; without Reverb the change shows on the next page load.
 */
class ConversationUpdated implements ShouldBroadcastNow, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public function __construct(public int $tenantId, public int $conversationId) {}

    /**
     * The name of the developer's channel of conversations.
     */
    public static function channelName(int $tenantId): string
    {
        return "tenants.{$tenantId}.conversations";
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->tenantId))];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array{conversation_id: int}
     */
    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
