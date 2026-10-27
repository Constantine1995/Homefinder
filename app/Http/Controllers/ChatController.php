<?php

namespace App\Http\Controllers;

use App\Enums\ConversationChannel;
use App\Enums\MessageRole;
use App\Http\Requests\SendMessageRequest;
use App\Jobs\AnswerConversation;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SavedSearch;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The test chat of the sales cabinet: an employee writes as a buyer and sees how the agent
 * answers — which tools it called, whether the reply passed the verifier, when it handed the dialog over.
 */
class ChatController extends Controller
{
    /**
     * Test conversations listed beside the chat.
     */
    public const int LISTED_CONVERSATIONS = 20;

    /**
     * Messages to start a test dialog with, one per dialog scenario.
     */
    public const array EXAMPLES = [
        'двушка до 9 млн, до метро пешком, сдача не позже 2027',
        'сколько в месяц по семейной ипотеке при взносе 2 млн?',
        'есть ли подземный паркинг в Отрадном квартале?',
        'а скидку сделаете, если возьму сразу?',
    ];

    /**
     * Show the test chat, with the chosen conversation if any.
     */
    public function show(?Conversation $conversation = null): Response
    {
        abort_if($conversation !== null && $conversation->channel !== ConversationChannel::Test, 404);

        $messages = $conversation?->messages()->with(['agentRun', 'savedSearch'])->orderBy('id')->get();

        return Inertia::render('chat/Index', [
            'examples' => self::EXAMPLES,
            'conversations' => fn (): array => $this->conversationList(),
            'conversation' => $conversation === null ? null : [
                'id' => $conversation->id,
                'status' => $conversation->status->value,
                'handoff_reason' => $conversation->handoff_reason,
            ],
            'messages' => $messages?->map($this->messageData(...))->all() ?? [],
            'waiting' => $conversation !== null
                && $conversation->isAnsweredByAgent()
                && $messages->last()?->role === MessageRole::Buyer,
        ]);
    }

    /**
     * Start a new test conversation.
     */
    public function store(): RedirectResponse
    {
        $conversation = Conversation::create(['channel' => ConversationChannel::Test]);

        return to_route('chat', $conversation);
    }

    /**
     * Write to the conversation as the buyer; the agent answers from the queue while it is the one to answer.
     */
    public function send(SendMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        abort_if($conversation->channel !== ConversationChannel::Test, 404);

        $message = $conversation->messages()->create(['role' => MessageRole::Buyer, 'text' => $request->text()]);
        $conversation->update(['last_message_at' => $message->created_at]);

        if ($conversation->isAnsweredByAgent()) {
            AnswerConversation::dispatch($conversation);
        }

        return to_route('chat', $conversation);
    }

    /**
     * End the subscription whose news the test dialog got, as the buyer's button does in Telegram.
     */
    public function unsubscribe(Conversation $conversation, SavedSearch $savedSearch): RedirectResponse
    {
        abort_if($conversation->channel !== ConversationChannel::Test || $savedSearch->conversation_id !== $conversation->id, 404);

        $savedSearch->update(['active' => false]);

        return to_route('chat', $conversation);
    }

    /**
     * @return list<array{id: int, status: string, title: string|null, last_message_at: string|null}>
     */
    private function conversationList(): array
    {
        return Conversation::query()
            ->where('channel', ConversationChannel::Test)
            ->addSelect(['title' => Message::query()
                ->select('text')
                ->whereColumn('conversation_id', 'conversations.id')
                ->orderBy('id')
                ->limit(1),
            ])
            ->latest('id')
            ->limit(self::LISTED_CONVERSATIONS)
            ->get()
            ->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'status' => $conversation->status->value,
                'title' => $conversation->title,
                'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array{id: int, role: string, text: string, created_at: string, run: array<string, mixed>|null, saved_search: array{id: int, active: bool}|null}
     */
    private function messageData(Message $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role->value,
            'text' => $message->text,
            'created_at' => $message->created_at->toIso8601String(),
            'run' => $message->agentRun === null ? null : $this->runData($message->agentRun),
            'saved_search' => $message->savedSearch?->only('id', 'active'),
        ];
    }

    /**
     * What the cabinet shows of a turn: the tools with what they told the model, and the check; the tools' raw data
     * stays in the log.
     *
     * @return array<string, mixed>
     */
    private function runData(AgentRun $run): array
    {
        return [
            'intent' => $run->intent,
            'steps' => array_map(
                fn (array $step): array => ['tool' => $step['tool'], 'args' => $step['args'], 'summary' => $step['summary']],
                $run->steps,
            ),
            'actions' => $run->actions,
            'verified' => $run->verified,
            'problems' => $run->problems,
            'attempts' => $run->attempts,
            'prompt_tokens' => $run->prompt_tokens,
            'completion_tokens' => $run->completion_tokens,
            'duration_ms' => $run->duration_ms,
            'error' => $run->error,
        ];
    }
}
