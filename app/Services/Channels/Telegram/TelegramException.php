<?php

namespace App\Services\Channels\Telegram;

use RuntimeException;
use Throwable;

/**
 * The Telegram Bot API could not be reached or refused a call.
 */
class TelegramException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $errorCode = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Whether the buyer blocked the bot or deleted the chat: repeating the call will not help.
     */
    public function isBlocked(): bool
    {
        return $this->errorCode === 403;
    }
}
