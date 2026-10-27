<?php

namespace App\Services\Dialogs;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\SavedSearch;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Answers the buyer's latest message with the AI agent and applies what the agent asks for.
 *
 * The agent service only reads: Laravel stores the reply and the agent's log, keeps the dialog state and the lead
 * card, and hands the conversation over by its own rules. Without the service the conversation goes to the managers straight away.
 */
class ConversationResponder
{
    /**
     * Messages of the dialog sent along as history; the agent reads the latest of them.
     */
    public const int HISTORY_MESSAGES = 20;

    /**
     * What the buyer reads when the agent cannot answer and a manager takes over.
     */
    public const string UNAVAILABLE_REPLY = 'Передаю ваш вопрос менеджеру отдела продаж — он ответит вам в ближайшее время.';

    /**
     * Why a turn's reply was not sent.
     */
    public const string TAKEN_DURING_TURN = 'Менеджер забрал диалог, пока агент отвечал; ответ не отправлен.';

    /**
     * The agent's tool whose turn leaves the search conditions in the dialog state, for the statistics.
     */
    public const string SEARCH_TOOL = 'search_flats';

    public function __construct(private AgentClient $agent, private LeadRecorder $leads) {}

    /**
     * Answer the conversation if its latest message is the buyer's and the agent is the one to answer.
     *
     * Several messages sent in a row get one answer: to the latest, with the earlier ones in the history.
     * The conversation's developer is made current, whoever calls: a request, a queued job or a channel's webhook.
     *
     * @return Message|null The reply for the buyer, or null when the agent was not the one to answer, or a manager
     *                      took the conversation during the turn.
     */
    public function respond(Conversation $conversation): ?Message
    {
        return $conversation->refresh()->tenant->run(fn (): ?Message => $this->answer($conversation));
    }

    private function answer(Conversation $conversation): ?Message
    {
        if (! $conversation->isAnsweredByAgent()) {
            return null;
        }

        $messages = $conversation->messages()
            ->latest('id')
            ->limit(self::HISTORY_MESSAGES + 1)
            ->get()
            ->reverse()
            ->values();
        $question = $messages->pop();

        if ($question?->role !== MessageRole::Buyer) {
            return null;
        }

        $history = $messages
            ->map(fn (Message $message): array => ['role' => $message->role->agentRole(), 'text' => $message->text])
            ->all();
        $startedAt = hrtime(true);

        try {
            $turn = $this->agent->chat(
                $conversation->tenant_id,
                $conversation->dialog,
                $history,
                $question->text,
                $conversation->channel->showsFlatCards(),
            );
        } catch (AgentException $exception) {
            Log::warning('The agent did not answer; the conversation goes to the managers.', ['exception' => $exception]);

            return $this->handOffUnanswered($conversation, $exception, $this->elapsedMilliseconds($startedAt));
        }

        return DB::transaction(function () use ($conversation, $turn, $startedAt): ?Message {
            if (! $this->stillAnsweredByAgent($conversation)) {
                return $this->dropTakenTurn($conversation, $turn, $this->elapsedMilliseconds($startedAt));
            }

            $reply = $conversation->messages()->create([
                'role' => MessageRole::Agent,
                'text' => $turn['reply'],
                'tool_calls' => array_map(
                    fn (array $step): array => ['tool' => $step['tool'], 'args' => $step['args']],
                    $turn['steps'],
                ),
            ]);

            $conversation->agentRuns()->create([
                'message_id' => $reply->id,
                ...$this->runAttributes($turn, $this->elapsedMilliseconds($startedAt)),
            ]);

            $conversation->update(['dialog' => $turn['dialog'], 'last_message_at' => $reply->created_at]);
            $this->leads->record($conversation, $turn['dialog']['lead'] ?? [], $turn['intent']);

            foreach ($turn['actions'] as $action) {
                match ($action['type']) {
                    'handoff' => $conversation->handOff($action['reason']),
                    'save_search' => $this->subscribe($conversation, $action['reason'], $action['filters'] ?? []),
                    default => null,
                };
            }

            return $reply;
        });
    }

    /**
     * Subscribe the buyer who asked the agent for it; asked again for the same conditions, the buyer keeps one
     * subscription, active again if it was ended.
     *
     * @param  array<string, mixed>  $filters
     */
    private function subscribe(Conversation $conversation, string $description, array $filters): void
    {
        if ($filters === []) {
            return;
        }

        $subscriptions = $conversation->contact_id === null
            ? SavedSearch::query()->where('conversation_id', $conversation->id)
            : SavedSearch::query()->where('contact_id', $conversation->contact_id);
        $same = $subscriptions->get()->first(fn (SavedSearch $search): bool => $search->filters == $filters);

        if ($same !== null) {
            $same->update(['active' => true, 'description' => $description]);

            return;
        }

        $conversation->savedSearches()->create([
            'contact_id' => $conversation->contact_id,
            'description' => $description,
            'filters' => $filters,
        ]);
    }

    private function handOffUnanswered(Conversation $conversation, AgentException $exception, int $durationMs): ?Message
    {
        $reason = 'сервис ИИ недоступен';

        return DB::transaction(function () use ($conversation, $exception, $durationMs, $reason): ?Message {
            if (! $this->stillAnsweredByAgent($conversation)) {
                return null;
            }

            $notice = $conversation->messages()->create(['role' => MessageRole::Agent, 'text' => self::UNAVAILABLE_REPLY]);

            $conversation->agentRuns()->create([
                'message_id' => $notice->id,
                'steps' => [],
                'actions' => [['type' => 'handoff', 'reason' => $reason]],
                'verified' => false,
                'problems' => [],
                'duration_ms' => $durationMs,
                'error' => $exception->getMessage(),
            ]);

            $conversation->update(['last_message_at' => $notice->created_at]);
            $conversation->handOff($reason);

            return $notice;
        });
    }

    /**
     * Whether the agent still answers, read under a lock: a manager taking the conversation waits for the reply to be
     * stored, or the reply waits for the taking and is dropped.
     */
    private function stillAnsweredByAgent(Conversation $conversation): bool
    {
        return $conversation->newQuery()->lockForUpdate()->findOrFail($conversation->id)->isAnsweredByAgent();
    }

    /**
     * Keep the log of a turn a manager took the conversation during; the buyer gets the manager's answer instead,
     * and the dialog state stays as the buyer last saw it.
     *
     * @param  array<string, mixed>  $turn
     */
    private function dropTakenTurn(Conversation $conversation, array $turn, int $durationMs): null
    {
        $conversation->agentRuns()->create([...$this->runAttributes($turn, $durationMs), 'error' => self::TAKEN_DURING_TURN]);

        return null;
    }

    /**
     * The agent's log of a turn.
     *
     * @param  array<string, mixed>  $turn
     * @return array<string, mixed>
     */
    private function runAttributes(array $turn, int $durationMs): array
    {
        return [
            'intent' => $turn['intent'],
            'steps' => $turn['steps'],
            'filters' => in_array(self::SEARCH_TOOL, array_column($turn['steps'], 'tool'), true)
                ? ($turn['dialog']['filters'] ?? null)
                : null,
            'actions' => $turn['actions'],
            'verified' => $turn['verified'],
            'problems' => $turn['problems'],
            'attempts' => $turn['attempts'],
            'prompt_tokens' => $turn['prompt_tokens'],
            'completion_tokens' => $turn['completion_tokens'],
            'duration_ms' => $durationMs,
        ];
    }

    private function elapsedMilliseconds(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
