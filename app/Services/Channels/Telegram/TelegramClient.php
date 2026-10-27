<?php

namespace App\Services\Channels\Telegram;

use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Calls the Telegram Bot API for the developers' bots; each call carries the token of one bot.
 */
class TelegramClient
{
    public function __construct(
        #[Config('services.telegram.api_url')] private string $apiUrl,
        #[Config('services.telegram.timeout')] private int $timeoutSeconds,
    ) {}

    /**
     * Get the bot with the given token.
     */
    public function bot(string $token): TelegramBot
    {
        return new TelegramBot($this, $token);
    }

    /**
     * Call a method of the Bot API and get its result.
     *
     * @param  array<string, mixed>  $parameters  Arrays, such as reply markup, are sent as JSON.
     * @param  array{name: string, contents: string, filename: string}|null  $file  A file to upload with the call.
     * @param  int  $waitSeconds  How long the server may hold the call, as getUpdates does when polling.
     *
     * @throws TelegramException
     */
    public function call(string $token, string $method, array $parameters = [], ?array $file = null, int $waitSeconds = 0): mixed
    {
        $request = Http::baseUrl("{$this->apiUrl}/bot{$token}")
            ->timeout($this->timeoutSeconds + $waitSeconds)
            ->acceptJson();

        try {
            $response = $file === null
                ? $request->post($method, $parameters)
                : $request->attach($file['name'], $file['contents'], $file['filename'])->post($method, array_map(
                    fn (mixed $value): mixed => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value,
                    $parameters,
                ));
        } catch (ConnectionException $exception) {
            throw new TelegramException("Telegram could not be reached for {$method}: {$exception->getMessage()}", previous: $exception);
        }

        if ($response->json('ok') !== true) {
            throw new TelegramException(
                "Telegram refused {$method} with HTTP {$response->status()}: ".($response->json('description') ?? $response->body()),
                $response->json('error_code') ?? $response->status(),
                $response->json('parameters.retry_after'),
            );
        }

        return $response->json('result');
    }
}
