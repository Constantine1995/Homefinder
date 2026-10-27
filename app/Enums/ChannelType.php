<?php

namespace App\Enums;

/**
 * A messenger or site widget buyers write to the developer through.
 */
enum ChannelType: string
{
    /**
     * The developer's Telegram bot.
     */
    case Telegram = 'telegram';

    /**
     * The chat widget on the developer's site.
     */
    case Widget = 'widget';
}
