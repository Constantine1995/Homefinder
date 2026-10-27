<?php

namespace App\Demo\Dialogs;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Services\Dialogs\ConversationResponder;
use Carbon\CarbonImmutable;
use Random\Randomizer;

/**
 * Writes one generated dialog turn by turn: the buyer's message, the agent's reply a few seconds later, and the
 * agent's log of the turn as ConversationResponder stores it.
 */
class DemoDialog
{
    public CarbonImmutable $lastAt;

    public ?string $handoffReason = null;

    public function __construct(
        private Conversation $conversation,
        public readonly CarbonImmutable $startedAt,
        private Randomizer $random,
    ) {
        $this->lastAt = $startedAt;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(string $request, array $filters, int $total): void
    {
        $reply = $total === 0
            ? 'Таких квартир сейчас нет. Могу ослабить условия: поднять бюджет или посмотреть другой ЖК — как вам удобнее?'
            : "Подобрал {$total} подходящих квартир, первые три — в карточках ниже. Посчитать ипотеку или записать на просмотр?";

        $this->turn($request, $reply, 'search', [
            'filters' => $filters,
            'steps' => [[
                'tool' => ConversationResponder::SEARCH_TOOL,
                'args' => ['request' => $request],
                'summary' => "Найдено квартир: {$total}.",
                'data' => ['total' => $total, 'flats' => []],
                'flat_ids' => [],
                'failed' => false,
            ]],
        ]);
    }

    public function mortgage(): void
    {
        $this->turn(
            'сколько в месяц по семейной ипотеке, если взнос 2 млн?',
            'По семейной ипотеке под 6% на 30 лет при взносе 2 млн ₽ платёж за первую квартиру — около 58 тыс. ₽ в месяц. Расчёт предварительный, точные условия подтвердит банк.',
            'mortgage',
            ['steps' => [$this->step('mortgage_calc', ['program' => 'Семейная ипотека', 'down_payment' => 2_000_000])]],
        );
    }

    public function knowledge(string $question, string $answer): void
    {
        $this->turn($question, $answer, 'knowledge', ['steps' => [$this->step('search_knowledge', ['query' => $question])]]);
    }

    public function gap(string $question): void
    {
        $this->turn($question, 'Хочу ответить точно, поэтому уточню у менеджера и вернусь с ответом.', 'knowledge', [
            'steps' => [$this->step('search_knowledge', ['query' => $question])],
            'actions' => [['type' => 'knowledge_gap', 'reason' => $question]],
        ]);
    }

    public function viewing(): void
    {
        $this->handOff('хочу посмотреть первую в субботу, запишите', 'запись на просмотр', 'booking');
    }

    /**
     * The agent hands the dialog over to the managers.
     */
    public function handOff(string $message, string $reason, string $intent = 'handoff'): void
    {
        $this->turn($message, 'Этот вопрос решит менеджер отдела продаж: передаю ему наш диалог, он напишет вам в ближайшее время.', $intent, [
            'actions' => [['type' => 'handoff', 'reason' => $reason]],
        ]);
        $this->handoffReason = $reason;
    }

    /**
     * The agent service does not answer, and the dialog goes to the managers.
     */
    public function unavailable(string $message): void
    {
        $this->turn($message, ConversationResponder::UNAVAILABLE_REPLY, null, [
            'actions' => [['type' => 'handoff', 'reason' => 'сервис ИИ недоступен']],
            'verified' => false,
            'error' => 'The agent service answered HTTP 503.',
        ]);
        $this->handoffReason = 'сервис ИИ недоступен';
    }

    /**
     * The model's reply named a price no tool gave, twice; the buyer got the fallback and a manager.
     */
    public function failedCheck(string $message): void
    {
        $reason = 'ответ не прошёл проверку: сумма «7,9 млн ₽» не из результатов инструментов';
        $this->turn($message, 'Хочу ответить точно, поэтому уточню у менеджера отдела продаж — он напишет вам в ближайшее время.', 'search', [
            'actions' => [['type' => 'handoff', 'reason' => $reason]],
            'verified' => false,
        ]);
        $this->handoffReason = $reason;
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function turn(string $message, string $reply, ?string $intent, array $run): void
    {
        $askedAt = $this->lastAt->addSeconds($this->random->getInt(20, 240));
        $seconds = $this->random->getInt(6, 45);
        $repliedAt = $askedAt->addSeconds($seconds);
        $verified = $run['verified'] ?? true;

        $this->conversation->messages()->forceCreate([
            'role' => MessageRole::Buyer,
            'text' => $message,
            'created_at' => $askedAt,
            'updated_at' => $askedAt,
        ]);
        $answer = $this->conversation->messages()->forceCreate([
            'role' => MessageRole::Agent,
            'text' => $reply,
            'tool_calls' => array_map(fn (array $step): array => ['tool' => $step['tool'], 'args' => $step['args']], $run['steps'] ?? []),
            'delivered_at' => $repliedAt,
            'created_at' => $repliedAt,
            'updated_at' => $repliedAt,
        ]);
        $this->conversation->agentRuns()->forceCreate([
            'message_id' => $answer->id,
            'intent' => $intent,
            'steps' => $run['steps'] ?? [],
            'filters' => $run['filters'] ?? null,
            'actions' => $run['actions'] ?? [],
            'verified' => $verified,
            'problems' => $verified ? [] : ['сумма не из результатов инструментов'],
            'attempts' => $verified ? 1 : 2,
            'prompt_tokens' => $this->random->getInt(1500, 4000),
            'completion_tokens' => $this->random->getInt(60, 250),
            'duration_ms' => $seconds * 1000,
            'error' => $run['error'] ?? null,
            'created_at' => $repliedAt,
            'updated_at' => $repliedAt,
        ]);

        $this->lastAt = $repliedAt;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function step(string $tool, array $args): array
    {
        return ['tool' => $tool, 'args' => $args, 'summary' => '', 'data' => [], 'flat_ids' => [], 'failed' => false];
    }
}
