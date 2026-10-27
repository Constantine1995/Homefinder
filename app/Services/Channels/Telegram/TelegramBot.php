<?php

namespace App\Services\Channels\Telegram;

/**
 * One developer's bot: the Bot API methods the product uses, with the bot's token.
 *
 * Texts go as plain text unless HTML is asked for, so a buyer's or a model's «<» never breaks a message.
 */
class TelegramBot
{
    public function __construct(private TelegramClient $client, private string $token) {}

    /**
     * Get the bot itself: its user ID and username.
     *
     * @return array{id: int, username: string, first_name: string}
     *
     * @throws TelegramException
     */
    public function getMe(): array
    {
        return $this->client->call($this->token, 'getMe');
    }

    /**
     * Send a text message and get its ID.
     *
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramException
     */
    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null, bool $html = false): int
    {
        return $this->client->call($this->token, 'sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $html ? 'HTML' : null,
            'reply_markup' => $replyMarkup,
            'link_preview_options' => ['is_disabled' => true],
        ], fn (mixed $value): bool => $value !== null))['message_id'];
    }

    /**
     * Send a picture with an HTML caption and get the file ID Telegram keeps it under, to send it again without uploading.
     *
     * @param  string  $photo  A file ID of a picture sent before, or the PNG to upload.
     * @param  array<string, mixed>|null  $replyMarkup
     *
     * @throws TelegramException
     */
    public function sendPhoto(string $chatId, string $photo, bool $upload, string $caption, ?array $replyMarkup = null): string
    {
        $parameters = array_filter([
            'chat_id' => $chatId,
            'photo' => $upload ? null : $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup,
        ], fn (mixed $value): bool => $value !== null);
        $file = $upload ? ['name' => 'photo', 'contents' => $photo, 'filename' => 'plan.png'] : null;

        $message = $this->client->call($this->token, 'sendPhoto', $parameters, $file);

        // Telegram returns the picture in several sizes, the largest last.
        return end($message['photo'])['file_id'];
    }

    /**
     * Show «typing…» in the chat for a few seconds.
     *
     * @throws TelegramException
     */
    public function sendTyping(string $chatId): void
    {
        $this->client->call($this->token, 'sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
    }

    /**
     * Stop the spinner on the button the buyer pressed, with a short notice if given.
     *
     * @throws TelegramException
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $this->client->call($this->token, 'answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Remove the buttons from a message the bot sent.
     *
     * @throws TelegramException
     */
    public function removeButtons(string $chatId, int $messageId): void
    {
        $this->client->call($this->token, 'editMessageReplyMarkup', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    /**
     * Wait for the updates after the given one, for polling without a webhook.
     *
     * @return list<array<string, mixed>>
     *
     * @throws TelegramException
     */
    public function getUpdates(?int $offset, int $waitSeconds): array
    {
        return $this->client->call($this->token, 'getUpdates', array_filter([
            'offset' => $offset,
            'timeout' => $waitSeconds,
            'allowed_updates' => ['message', 'callback_query'],
        ], fn (mixed $value): bool => $value !== null), waitSeconds: $waitSeconds);
    }

    /**
     * Have Telegram post the bot's updates to the URL, with the secret in the X-Telegram-Bot-Api-Secret-Token header.
     *
     * @throws TelegramException
     */
    public function setWebhook(string $url, string $secret): void
    {
        $this->client->call($this->token, 'setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
    }

    /**
     * Stop posting updates to the webhook, so they can be polled.
     *
     * @throws TelegramException
     */
    public function deleteWebhook(): void
    {
        $this->client->call($this->token, 'deleteWebhook');
    }
}
