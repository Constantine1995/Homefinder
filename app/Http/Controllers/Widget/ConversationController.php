<?php

namespace App\Http\Controllers\Widget;

use App\Enums\ConversationStatus;
use App\Enums\FlatStatus;
use App\Enums\MessageRole;
use App\Events\WidgetMessageSent;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Http\Requests\ShareWidgetPhoneRequest;
use App\Http\Resources\Widget\MessageResource;
use App\Jobs\AnswerConversation;
use App\Models\Contact;
use App\Models\Flat;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Services\Catalog\FlatLabels;
use App\Services\Dialogs\ShownFlats;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * The visitor's dialog in the site widget: the same agent and the same managers as in Telegram.
 *
 * Every action answers with the dialog as the widget shows it, so the widget needs no second request; the agent's
 * reply comes later, and the widget learns of it over its private channel (App\Events\WidgetMessageSent) or by asking
 * again while it waits.
 */
class ConversationController extends Controller
{
    /**
     * The latest messages the widget shows, of all the visitor's conversations.
     */
    public const int SHOWN_MESSAGES = 50;

    public const string CONSENT_FIRST = 'Чтобы продолжить, подтвердите согласие на обработку персональных данных.';

    public const string FLAT_GONE = 'Эта квартира уже не продаётся.';

    /**
     * Show the visitor's dialog; the managers' and the agent's messages count as delivered once the widget got them.
     */
    public function show(#[CurrentUser('widget')] Contact $visitor): JsonResponse
    {
        return $this->dialog($visitor);
    }

    /**
     * Take the visitor's consent to the processing of personal data; until then the visitor cannot write.
     */
    public function consent(#[CurrentUser('widget')] Contact $visitor): JsonResponse
    {
        if (! $visitor->hasConsented()) {
            $visitor->update(['consent_at' => now()]);
        }

        return $this->dialog($visitor);
    }

    /**
     * Write to the developer as the visitor; the agent answers from the queue while it is the one to answer.
     */
    public function send(SendMessageRequest $request, #[CurrentUser('widget')] Contact $visitor): JsonResponse
    {
        abort_unless($visitor->hasConsented(), 409, self::CONSENT_FIRST);

        $this->write($visitor, $request->text());

        return $this->dialog($visitor, 201);
    }

    /**
     * Press a button of a flat's card: «Записаться» or «Похожие» write the visitor's request as a message, as in Telegram.
     */
    public function flat(Flat $flat, string $request, #[CurrentUser('widget')] Contact $visitor): JsonResponse
    {
        abort_unless($visitor->hasConsented(), 409, self::CONSENT_FIRST);
        abort_unless(in_array($flat->status, [FlatStatus::Available, FlatStatus::Reserved], true), 404, self::FLAT_GONE);

        $flat->loadMissing('building.complex');
        $this->write($visitor, $request === 'booking' ? FlatLabels::bookingMessage($flat) : FlatLabels::similarMessage($flat));

        return $this->dialog($visitor, 201);
    }

    /**
     * Keep the phone the visitor left for the manager; a buyer who can be called is a warmer lead.
     */
    public function phone(ShareWidgetPhoneRequest $request, #[CurrentUser('widget')] Contact $visitor): JsonResponse
    {
        $visitor->update(['phone' => $request->phone()]);
        Lead::query()->where('contact_id', $visitor->id)->get()->each->save();

        return $this->dialog($visitor);
    }

    /**
     * End the visitor's own subscription; another buyer's subscription looks like none.
     */
    public function unsubscribe(SavedSearch $savedSearch, #[CurrentUser('widget')] Contact $visitor): JsonResponse
    {
        abort_unless($savedSearch->contact_id === $visitor->id, 404);

        $savedSearch->update(['active' => false]);

        return $this->dialog($visitor);
    }

    private function write(Contact $visitor, string $text): void
    {
        $conversation = $visitor->openConversation();
        $message = $conversation->messages()->create(['role' => MessageRole::Buyer, 'text' => $text]);
        $conversation->update(['last_message_at' => $message->created_at]);

        if ($conversation->isAnsweredByAgent()) {
            AnswerConversation::dispatch($conversation);
        }
    }

    /**
     * The dialog as the widget shows it: whether the visitor may write, whether the agent is answering, whether the
     * manager waits for a phone, and the latest messages with the flats' cards.
     */
    private function dialog(Contact $visitor, int $status = 200): JsonResponse
    {
        $conversation = $visitor->conversations()->latest('id')->first();
        $messages = Message::query()
            ->whereIn('conversation_id', $visitor->conversations()->select('id'))
            ->with(['agentRun', 'savedSearch'])
            ->latest('id')
            ->limit(self::SHOWN_MESSAGES)
            ->get()
            ->reverse()
            ->values();

        $shownFlats = ShownFlats::ofMessages($messages);
        $messages->each(fn (Message $message) => $message->setRelation('shownFlats', $shownFlats->get($message->id, collect())));

        $undelivered = $messages->filter(fn (Message $message): bool => $message->role !== MessageRole::Buyer && $message->delivered_at === null);
        Message::query()->whereKey($undelivered->modelKeys())->update(['delivered_at' => now()]);

        return response()->json([
            'channel' => WidgetMessageSent::channelName($visitor->id),
            'consented' => $visitor->hasConsented(),
            'status' => $conversation?->status->value,
            'waiting' => $conversation?->isAnsweredByAgent() === true && $messages->last()?->role === MessageRole::Buyer,
            'phone_requested' => $conversation?->status === ConversationStatus::Human && $visitor->phone === null,
            'messages' => MessageResource::collection($messages),
        ], $status);
    }
}
