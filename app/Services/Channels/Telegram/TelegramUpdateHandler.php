<?php

namespace App\Services\Channels\Telegram;

use App\Enums\FlatStatus;
use App\Enums\MessageRole;
use App\Jobs\AnswerConversation;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flat;
use App\Models\Lead;
use App\Models\SavedSearch;
use App\Services\Catalog\FlatLabels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Takes an update of a developer's Telegram bot, from the webhook or from polling: a buyer's message or a pressed button.
 *
 * Before anything else the buyer agrees to the processing of personal data; until then messages are kept and
 * answered after the consent. The developer comes from the bot, never from what the buyer wrote.
 */
class TelegramUpdateHandler
{
    /**
     * Messages and button presses a buyer may send in an hour; the rest are left unanswered.
     */
    public const int MESSAGES_PER_HOUR = 30;

    /**
     * Callback data of the consent button.
     */
    public const string CONSENT = 'consent';

    public const string GREETING = 'Здравствуйте! Чем могу помочь? Напишите, какую квартиру ищете, или задайте вопрос о ЖК и ипотеке.';

    public const string THANKS_FOR_CONSENT = 'Спасибо! Напишите, какую квартиру ищете, или задайте вопрос о ЖК и ипотеке.';

    public const string TEXT_ONLY = 'Пока я понимаю только текст — напишите, пожалуйста, вопрос словами.';

    public const string TOO_MANY_MESSAGES = 'Сообщений слишком много — я отвечу чуть позже. Если вопрос срочный, попросите менеджера.';

    public const string THANKS_FOR_PHONE = 'Спасибо, телефон передан менеджеру.';

    public const string FLAT_GONE = 'Эта квартира уже не продаётся.';

    public const string UNSUBSCRIBED = 'Подписка отключена.';

    public function __construct(private TelegramClient $telegram) {}

    /**
     * Handle the update once, however many times Telegram delivers it.
     *
     *
     * @param  array<string, mixed>  $update
     *
     * @throws TelegramException
     */
    public function handle(Channel $channel, array $update): void
    {
        $key = "telegram:{$channel->id}:update:{$update['update_id']}";

        if (! $channel->is_active || ! Cache::add($key, true, now()->addDay())) {
            return;
        }

        try {
            $channel->tenant->run(function () use ($channel, $update): void {
                $bot = $this->telegram->bot($channel->token());

                match (true) {
                    isset($update['callback_query']) => $this->pressed($channel, $bot, $update['callback_query']),
                    isset($update['message']) => $this->received($channel, $bot, $update['message']),
                    default => null,
                };
            });
        } catch (Throwable $exception) {
            // A retry handles the update again: a message kept twice gets one answer, a lost one gets none.
            Cache::forget($key);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function received(Channel $channel, TelegramBot $bot, array $message): void
    {
        // A bot of a developer talks to buyers one to one; groups it was added to are not its business.
        if (($message['chat']['type'] ?? null) !== 'private') {
            return;
        }

        $contact = $this->contact($channel, $message['chat'], $message['from'] ?? []);

        if (! $this->withinLimit($contact, $bot)) {
            return;
        }

        if (isset($message['contact'])) {
            $this->sharedPhone($contact, $bot, $message['contact']);

            return;
        }

        $text = trim($message['text'] ?? '');

        if ($text === '') {
            $bot->sendMessage($contact->channel_user_id, self::TEXT_ONLY);

            return;
        }

        if (str_starts_with($text, '/start')) {
            $contact->hasConsented()
                ? $bot->sendMessage($contact->channel_user_id, self::GREETING)
                : $this->askConsent($channel, $contact, $bot);

            return;
        }

        $conversation = $this->write($contact, $text);

        if (! $contact->hasConsented()) {
            $this->askConsent($channel, $contact, $bot);

            return;
        }

        $this->answer($conversation, $bot);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function pressed(Channel $channel, TelegramBot $bot, array $query): void
    {
        $chatId = $query['message']['chat']['id'] ?? null;
        $contact = $chatId === null ? null : Contact::query()
            ->where('channel_id', $channel->id)
            ->where('channel_user_id', (string) $chatId)
            ->first();

        if ($contact === null || ! $this->withinLimit($contact, $bot)) {
            $bot->answerCallbackQuery($query['id']);

            return;
        }

        $data = (string) ($query['data'] ?? '');

        if ($data === self::CONSENT) {
            $this->consented($contact, $bot, $query);

            return;
        }

        if (str_starts_with($data, TelegramMessenger::UNSUBSCRIBE)) {
            $this->unsubscribed($contact, $bot, $query, substr($data, strlen(TelegramMessenger::UNSUBSCRIBE)));

            return;
        }

        if (! $contact->hasConsented()) {
            $bot->answerCallbackQuery($query['id']);
            $this->askConsent($channel, $contact, $bot);

            return;
        }

        $flat = $this->pressedFlat($data);

        if ($flat === null) {
            $bot->answerCallbackQuery($query['id'], self::FLAT_GONE);

            return;
        }

        $bot->answerCallbackQuery($query['id']);
        $text = str_starts_with($data, FlatCard::BOOK) ? FlatLabels::bookingMessage($flat) : FlatLabels::similarMessage($flat);

        $this->answer($this->write($contact, $text), $bot);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function consented(Contact $contact, TelegramBot $bot, array $query): void
    {
        if (! $contact->hasConsented()) {
            $contact->update(['consent_at' => now()]);
        }

        $bot->answerCallbackQuery($query['id']);
        $this->quietly(fn () => $bot->removeButtons($contact->channel_user_id, $query['message']['message_id']));

        $conversation = $contact->conversations()->latest('id')->first();
        $waiting = $conversation?->isAnsweredByAgent() && $conversation->awaitsAnswer();

        $waiting ? $this->answer($conversation, $bot) : $bot->sendMessage($contact->channel_user_id, self::THANKS_FOR_CONSENT);
    }

    /**
     * End the buyer's own subscription; a button pressed twice, or of another buyer's subscription, ends nothing.
     *
     * @param  array<string, mixed>  $query
     */
    private function unsubscribed(Contact $contact, TelegramBot $bot, array $query, string $id): void
    {
        $search = ctype_digit($id) ? SavedSearch::query()->where('contact_id', $contact->id)->find((int) $id) : null;
        $search?->update(['active' => false]);

        $bot->answerCallbackQuery($query['id'], $search === null ? null : self::UNSUBSCRIBED);
        $this->quietly(fn () => $bot->removeButtons($contact->channel_user_id, $query['message']['message_id']));
    }

    /**
     * @param  array<string, mixed>  $sharedContact
     */
    private function sharedPhone(Contact $contact, TelegramBot $bot, array $sharedContact): void
    {
        // Only the buyer's own number, sent with the button: a forwarded card of someone else is not the buyer's phone.
        if (($sharedContact['user_id'] ?? null) === null || (string) $sharedContact['user_id'] !== $contact->channel_user_id) {
            $bot->sendMessage($contact->channel_user_id, self::TEXT_ONLY);

            return;
        }

        $phone = '+'.ltrim($sharedContact['phone_number'], '+');
        $contact->update(['phone' => $phone]);
        // A buyer who can be called is a warmer lead.
        Lead::query()->where('contact_id', $contact->id)->get()->each->save();
        $bot->sendMessage($contact->channel_user_id, self::THANKS_FOR_PHONE, ['remove_keyboard' => true]);
    }

    /**
     * @param  array<string, mixed>  $chat
     * @param  array<string, mixed>  $from
     */
    private function contact(Channel $channel, array $chat, array $from): Contact
    {
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));

        return Contact::query()->firstOrCreate(
            ['channel_id' => $channel->id, 'channel_user_id' => (string) $chat['id']],
            ['name' => $name === '' ? null : $name, 'username' => $from['username'] ?? null],
        );
    }

    private function write(Contact $contact, string $text): Conversation
    {
        $conversation = $contact->openConversation();
        $message = $conversation->messages()->create(['role' => MessageRole::Buyer, 'text' => $text]);
        $conversation->update(['last_message_at' => $message->created_at]);

        return $conversation;
    }

    /**
     * Have the agent answer, while it is the one to answer; a conversation with a manager waits for the manager.
     */
    private function answer(Conversation $conversation, TelegramBot $bot): void
    {
        if (! $conversation->isAnsweredByAgent()) {
            return;
        }

        $this->quietly(fn () => $bot->sendTyping($conversation->contact->channel_user_id));
        AnswerConversation::dispatch($conversation);
    }

    private function askConsent(Channel $channel, Contact $contact, TelegramBot $bot): void
    {
        $bot->sendMessage(
            $contact->channel_user_id,
            $channel->introduction()
            ."\n\nЧтобы продолжить, подтвердите согласие на обработку персональных данных: {$channel->policyUrl()}",
            ['inline_keyboard' => [[['text' => 'Согласен', 'callback_data' => self::CONSENT]]]],
        );
    }

    private function pressedFlat(string $data): ?Flat
    {
        foreach ([FlatCard::BOOK, FlatCard::SIMILAR] as $prefix) {
            if (str_starts_with($data, $prefix) && ctype_digit($id = substr($data, strlen($prefix)))) {
                return Flat::query()
                    ->with('building.complex')
                    ->whereIn('status', [FlatStatus::Available, FlatStatus::Reserved])
                    ->find((int) $id);
            }
        }

        return null;
    }

    /**
     * Count the buyer's message; past the hourly limit the buyer is told once and the rest are dropped.
     */
    private function withinLimit(Contact $contact, TelegramBot $bot): bool
    {
        $key = "telegram-contact:{$contact->id}";
        $attempts = RateLimiter::hit($key, 3600);

        if ($attempts === self::MESSAGES_PER_HOUR + 1) {
            $bot->sendMessage($contact->channel_user_id, self::TOO_MANY_MESSAGES);
        }

        return $attempts <= self::MESSAGES_PER_HOUR;
    }

    /**
     * Run a call whose failure the buyer would not notice, such as «typing…».
     */
    private function quietly(callable $call): void
    {
        try {
            $call();
        } catch (TelegramException $exception) {
            Log::info('A Telegram call of no consequence failed.', ['exception' => $exception]);
        }
    }
}
