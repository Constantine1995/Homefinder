<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the visitor's open site widget that the sales side wrote: the agent's reply, a manager, or a subscription's news.
 *
 * Only the IDs go over the websocket; the widget fetches the dialog with the visitor's token. Without Reverb the
 * widget sees the message when it asks again.
 */
class WidgetMessageSent implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;

    public function __construct(public int $contactId, public int $messageId) {}

    /**
     * The name of the visitor's channel.
     */
    public static function channelName(int $contactId): string
    {
        return "widget.contacts.{$contactId}";
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->contactId))];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array{message_id: int}
     */
    public function broadcastWith(): array
    {
        return ['message_id' => $this->messageId];
    }
}
