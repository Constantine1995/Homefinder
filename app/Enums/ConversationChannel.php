<?php

namespace App\Enums;

/**
 * Where the buyer writes from.
 */
enum ConversationChannel: string
{
    /**
     * The test chat of the sales cabinet, where employees talk to the agent as a buyer would.
     */
    case Test = 'test';

    /**
     * The developer's Telegram bot.
     */
    case Telegram = 'telegram';

    /**
     * The chat widget on the developer's site.
     */
    case Widget = 'widget';

    /**
     * Whether the flats the agent finds are sent as cards under its reply, so the reply need not list them.
     */
    public function showsFlatCards(): bool
    {
        return $this !== self::Test;
    }
}
