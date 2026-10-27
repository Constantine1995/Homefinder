<?php

namespace App\Services\Channels\Telegram;

use App\Enums\MessageRole;
use App\Models\Flat;
use App\Models\Message;
use App\Services\Catalog\PlanPictures;
use App\Services\Dialogs\ShownFlats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Delivers the sales side's messages to a buyer's Telegram chat: the text, the cards of the flats the agent found,
 * and, once the dialog is with a manager, a button to share the phone.
 */
class TelegramMessenger
{
    /**
     * Telegram's limit on a message's length.
     */
    public const int MAX_TEXT_LENGTH = 4096;

    /**
     * How long Telegram's file ID of a drawn plan is kept: plans rarely change, and a new plan has a new URL.
     */
    public const int PLAN_FILE_DAYS = 30;

    public const string PHONE_REQUEST = 'Чтобы менеджер мог с вами связаться, поделитесь, пожалуйста, телефоном — кнопка ниже.';

    public const string PHONE_BUTTON = 'Отправить телефон';

    /**
     * The callback data of the unsubscribe button, followed by the subscription's ID.
     */
    public const string UNSUBSCRIBE = 'unsubscribe:';

    public const string UNSUBSCRIBE_BUTTON = 'Отписаться';

    public function __construct(private TelegramClient $telegram, private PlanPictures $plans) {}

    /**
     * Send the message to the buyer's chat.
     *
     * A message whose text is already delivered gets only its cards: a retry after a failed card does not repeat the text.
     *
     * @throws TelegramException
     */
    public function deliver(Message $message): void
    {
        $conversation = $message->conversation;
        $contact = $conversation->contact;
        $bot = $this->telegram->bot($contact->channel->token());

        if ($message->delivered_at === null) {
            $buttons = $message->saved_search_id === null ? null : self::unsubscribeButton($message->saved_search_id);
            $bot->sendMessage($contact->channel_user_id, self::html($message->text), $buttons, html: true);
            $message->update(['delivered_at' => now()]);

            if ($message->role === MessageRole::Agent && ! $conversation->isAnsweredByAgent() && $contact->phone === null) {
                $bot->sendMessage($contact->channel_user_id, self::PHONE_REQUEST, [
                    'keyboard' => [[['text' => self::PHONE_BUTTON, 'request_contact' => true]]],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true,
                ]);
            }
        }

        foreach (ShownFlats::of($message) as $flat) {
            $this->sendCard($bot, $contact->channel_user_id, $contact->channel_id, $flat);
        }
    }

    /**
     * The button under the news of a subscription that ends it.
     *
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function unsubscribeButton(int $savedSearchId): array
    {
        return ['inline_keyboard' => [[['text' => self::UNSUBSCRIBE_BUTTON, 'callback_data' => self::UNSUBSCRIBE.$savedSearchId]]]];
    }

    /**
     * Write an agent's reply in Telegram's HTML: the text escaped, the model's «**bold**» made bold.
     */
    public static function html(string $text): string
    {
        $html = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', e($text, false));
        $html = preg_replace('/[ \t]+$/mu', '', $html);

        return Str::limit($html, self::MAX_TEXT_LENGTH - 100, '…', preserveWords: true);
    }

    /**
     * Send the flat with the picture of its plan; a plan sent before goes by Telegram's file ID, without uploading.
     *
     * @throws TelegramException
     */
    private function sendCard(TelegramBot $bot, string $chatId, int $channelId, Flat $flat): void
    {
        $caption = FlatCard::caption($flat);
        $buttons = FlatCard::buttons($flat);

        if ($flat->plan_url === null) {
            $bot->sendMessage($chatId, $caption, $buttons, html: true);

            return;
        }

        $cacheKey = "telegram:{$channelId}:plan:".sha1($flat->plan_url);
        $fileId = Cache::get($cacheKey);

        if ($fileId !== null) {
            $bot->sendPhoto($chatId, $fileId, false, $caption, $buttons);

            return;
        }

        $picture = $this->plans->picture($flat->plan_url);

        if ($picture === null) {
            $bot->sendMessage($chatId, $caption, $buttons, html: true);

            return;
        }

        Cache::put($cacheKey, $bot->sendPhoto($chatId, $picture, true, $caption, $buttons), now()->addDays(self::PLAN_FILE_DAYS));
    }
}
