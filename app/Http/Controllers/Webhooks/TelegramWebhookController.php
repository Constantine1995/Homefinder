<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\HandleTelegramUpdate;
use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Takes the updates Telegram posts for a developer's bot and leaves them to the queue.
 */
class TelegramWebhookController extends Controller
{
    /**
     * Accept the update if it comes with the bot's secret; anything else looks like no such webhook.
     */
    public function __invoke(Request $request, Channel $channel): Response
    {
        abort_unless(
            hash_equals($channel->webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))
                && is_int($request->input('update_id')),
            404,
        );

        HandleTelegramUpdate::dispatch($channel, $request->all());

        return response()->noContent(200);
    }
}
