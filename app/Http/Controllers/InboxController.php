<?php

namespace App\Http\Controllers;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\InboxFilter;
use App\Enums\MessageRole;
use App\Http\Requests\SendMessageRequest;
use App\Jobs\AnswerConversation;
use App\Jobs\DeliverMessage;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shared chat of the sales department: every conversation of the developer as it happens. A manager takes a
 * conversation from the agent or after its handoff, writes to the buyer in their channel, then hands it back to the
 * agent or closes it.
 */
class InboxController extends Controller
{
    /**
     * Conversations listed beside the chat.
     */
    public const int LISTED_CONVERSATIONS = 50;

    /**
     * Characters of the latest message shown under a listed conversation.
     */
    public const int PREVIEW_LENGTH = 80;

    /**
     * Show the shared chat, with the chosen conversation if any.
     */
    public function show(Request $request, ?Conversation $conversation = null): Response
    {
        $user = $request->user();
        $filter = $request->enum('filter', InboxFilter::class) ?? InboxFilter::Open;
        $messages = $conversation?->messages()->with('author')->orderBy('id')->get();

        return Inertia::render('inbox/Index', [
            'filter' => $filter->value,
            'counts' => fn (): array => [
                'waiting' => $this->filtered(InboxFilter::Waiting, $user)->count(),
                'mine' => $this->filtered(InboxFilter::Mine, $user)->count(),
            ],
            'conversations' => fn (): array => $this->conversationList($filter, $user),
            'conversation' => $conversation === null ? null : $this->conversationData($conversation, $user),
            'messages' => $messages?->map($this->messageData(...))->all() ?? [],
            'waiting' => $conversation !== null
                && $conversation->isAnsweredByAgent()
                && $messages->last()?->role === MessageRole::Buyer,
        ]);
    }

    /**
     * Write to the buyer as the manager who took the conversation; a buyer in a channel gets the message there.
     */
    public function reply(SendMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('reply', $conversation);

        $message = $conversation->messages()->create([
            'role' => MessageRole::Manager,
            'user_id' => $request->user()->id,
            'text' => $request->text(),
        ]);
        $conversation->update(['last_message_at' => $message->created_at]);

        if ($conversation->contact_id !== null) {
            DeliverMessage::dispatch($message);
        }

        return back();
    }

    /**
     * Take the conversation: the agent stops answering, and only this manager writes to the buyer.
     */
    public function take(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->whileLocked($conversation, function (Conversation $conversation) use ($request): void {
            Gate::authorize('take', $conversation);
            $conversation->takeBy($request->user());
        });

        return back();
    }

    /**
     * Hand the conversation back to the agent, which answers the buyer's message if it is still unanswered.
     */
    public function handBack(Conversation $conversation): RedirectResponse
    {
        $this->whileLocked($conversation, function (Conversation $conversation): void {
            Gate::authorize('handBack', $conversation);
            $conversation->handBack();
        });

        if ($conversation->refresh()->awaitsAnswer()) {
            AnswerConversation::dispatch($conversation);
        }

        return back();
    }

    /**
     * Close the conversation; the buyer's next message starts a new one.
     */
    public function close(Conversation $conversation): RedirectResponse
    {
        $this->whileLocked($conversation, function (Conversation $conversation): void {
            Gate::authorize('close', $conversation);
            $conversation->close();
        });

        return back();
    }

    /**
     * Decide and change the conversation on its row locked, as the agent's turn stores its reply: two managers taking
     * it at once, or a manager and the agent, act one after the other on what the first one left.
     *
     * @param  callable(Conversation): void  $change
     */
    private function whileLocked(Conversation $conversation, callable $change): void
    {
        DB::transaction(fn () => $change($conversation->newQuery()->lockForUpdate()->findOrFail($conversation->id)));
    }

    /**
     * @return Builder<Conversation>
     */
    private function filtered(InboxFilter $filter, User $user): Builder
    {
        $query = Conversation::query();

        return match ($filter) {
            InboxFilter::Open => $query->whereNot('status', ConversationStatus::Closed),
            InboxFilter::Waiting => $query->where('status', ConversationStatus::Human)->whereNull('assignee_id'),
            InboxFilter::Mine => $query->whereNot('status', ConversationStatus::Closed)->where('assignee_id', $user->id),
            InboxFilter::Closed => $query->where('status', ConversationStatus::Closed),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conversationList(InboxFilter $filter, User $user): array
    {
        $latest = fn (string $column): Builder => Message::query()
            ->select($column)
            ->whereColumn('conversation_id', 'conversations.id')
            ->latest('id')
            ->limit(1);

        return $this->filtered($filter, $user)
            ->with(['contact', 'assignee', 'lead'])
            ->whereHas('messages')
            ->addSelect(['last_text' => $latest('text'), 'last_role' => $latest('role')])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(self::LISTED_CONVERSATIONS)
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'channel' => $conversation->channel->value,
                'status' => $conversation->status->value,
                'title' => $this->title($conversation),
                'preview' => Str::limit($conversation->last_text, self::PREVIEW_LENGTH),
                'last_role' => $conversation->last_role,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                'assignee' => $conversation->assignee?->name,
                'score' => $conversation->lead?->score->value,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversationData(Conversation $conversation, User $user): array
    {
        $contact = $conversation->contact;

        return [
            'id' => $conversation->id,
            'channel' => $conversation->channel->value,
            'status' => $conversation->status->value,
            'title' => $this->title($conversation),
            'handoff_reason' => $conversation->handoff_reason,
            'assignee' => $conversation->assignee?->only('id', 'name'),
            'contact' => $contact === null ? null : [
                'name' => $contact->name,
                'username' => $contact->username,
                'phone' => $contact->phone,
            ],
            'lead' => $this->leadData($conversation->lead),
            'saved_searches' => ($conversation->contact_id === null
                ? SavedSearch::query()->where('conversation_id', $conversation->id)
                : SavedSearch::query()->where('contact_id', $conversation->contact_id))
                ->where('active', true)
                ->orderBy('id')
                ->pluck('description')
                ->all(),
            'can' => [
                'take' => $user->can('take', $conversation) && $conversation->assignee_id !== $user->id,
                'reply' => $user->can('reply', $conversation),
                'hand_back' => $user->can('handBack', $conversation),
                'close' => $user->can('close', $conversation),
            ],
        ];
    }

    /**
     * @return array{score: string, budget: int|null, payment: string|null, rooms: list<int>, deadline_year: int|null, family: string|null, sells_own_flat: bool|null, wants_viewing: bool}|null
     */
    private function leadData(?Lead $lead): ?array
    {
        return $lead === null ? null : [
            'score' => $lead->score->value,
            'budget' => $lead->budget,
            'payment' => $lead->payment?->value,
            'rooms' => $lead->rooms,
            'deadline_year' => $lead->deadline_year,
            'family' => $lead->family,
            'sells_own_flat' => $lead->sells_own_flat,
            'wants_viewing' => $lead->wants_viewing,
        ];
    }

    /**
     * @return array{id: int, role: string, text: string, author: string|null, created_at: string, delivered_at: string|null}
     */
    private function messageData(Message $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'text' => $message->text,
            'author' => $message->author?->name,
            'created_at' => $message->created_at->toIso8601String(),
            'delivered_at' => $message->delivered_at?->toIso8601String(),
        ];
    }

    /**
     * Name the conversation by its buyer; a test conversation has none.
     */
    private function title(Conversation $conversation): string
    {
        $contact = $conversation->contact;

        return match (true) {
            $conversation->channel === ConversationChannel::Test => "Тестовый диалог №{$conversation->id}",
            $contact?->name !== null => $contact->name,
            $contact?->username !== null => "@{$contact->username}",
            $conversation->channel === ConversationChannel::Widget => "Посетитель сайта №{$conversation->id}",
            default => "Покупатель №{$conversation->id}",
        };
    }
}
