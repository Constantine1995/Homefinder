<?php

namespace App\Services\Statistics;

use App\Enums\ConversationChannel;
use App\Enums\LeadScore;
use App\Enums\MessageRole;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Lead;
use App\Services\Dialogs\ConversationResponder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The sales head's numbers for a period: how buyers' dialogs turn into leads and viewing requests, how many come
 * when the office is closed, why the agent hands dialogs over, and what buyers ask for that the developer lacks.
 *
 * Counted are the dialogs of buyers in channels; the employees' test chat is left out. Rows are the current
 * developer's, as everywhere in the cabinet.
 */
class SalesStatistics
{
    /**
     * Rows in each list: handoff reasons, searches with nothing found, questions without an answer.
     */
    public const int LISTED_ROWS = 10;

    /**
     * How the agent's handoff reason starts when its reply failed the verifier (agent/src/homefinder/agent/graph.py).
     */
    public const string FAILED_CHECK = 'ответ не прошёл проверку';

    /**
     * Searches are grouped by price rounded up to this step: «до 11,5 млн» and «до 12 млн» are the same demand.
     */
    public const int PRICE_STEP = 1_000_000;

    /**
     * @return array<string, mixed>
     */
    public function report(CarbonImmutable $since): array
    {
        $conversationIds = $this->conversations($since)->select('id');

        return [
            'funnel' => $this->funnel($since),
            'days' => $this->days($since),
            'handoff_reasons' => $this->handoffReasons($conversationIds),
            'agent' => $this->agent($conversationIds),
            'unmet_demand' => $this->unmetDemand($conversationIds),
            'knowledge_gaps' => $this->knowledgeGaps($conversationIds),
        ];
    }

    /**
     * Dialogs that buyers started in the period, in a channel.
     *
     * @return Builder<Conversation>
     */
    private function conversations(CarbonImmutable $since): Builder
    {
        return Conversation::query()
            ->where('created_at', '>=', $since)
            ->whereNot('channel', ConversationChannel::Test)
            ->whereHas('messages', fn (Builder $query) => $query->where('role', MessageRole::Buyer));
    }

    /**
     * Dialogs → leads → hot and warm leads → viewing requests, with the share of dialogs started out of office hours.
     *
     * @return array{conversations: int, out_of_hours: int, leads: int, scores: array<string, int>, viewing_requests: int}
     */
    private function funnel(CarbonImmutable $since): array
    {
        $conversations = $this->conversations($since)
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where {$this->outOfHours('conversations.created_at')}) as out_of_hours", $this->officeHours())
            ->toBase()
            ->first();
        $leads = Lead::query()
            ->whereIn('conversation_id', $this->conversations($since)->select('id'))
            ->selectRaw('score, count(*) as total, count(*) filter (where wants_viewing) as viewing')
            ->groupBy('score')
            ->toBase()
            ->get()
            ->keyBy('score');

        return [
            'conversations' => (int) $conversations->total,
            'out_of_hours' => (int) $conversations->out_of_hours,
            'leads' => (int) $leads->sum('total'),
            'scores' => collect(LeadScore::cases())
                ->mapWithKeys(fn (LeadScore $score): array => [$score->value => (int) ($leads[$score->value]->total ?? 0)])
                ->all(),
            'viewing_requests' => (int) $leads->sum('viewing'),
        ];
    }

    /**
     * Dialogs started on each day of the period, in the office's time, days without dialogs included.
     *
     * @return list<array{date: string, conversations: int, out_of_hours: int}>
     */
    private function days(CarbonImmutable $since): array
    {
        $timezone = config('sales.timezone');
        $counted = $this->conversations($since)
            ->selectRaw("(conversations.created_at at time zone 'UTC' at time zone ?)::date as day", [$timezone])
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where {$this->outOfHours('conversations.created_at')}) as out_of_hours", $this->officeHours())
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy('day');

        $days = [];
        for ($day = $since->setTimezone($timezone)->startOfDay(); $day->isPast(); $day = $day->addDay()) {
            $row = $counted[$day->toDateString()] ?? null;
            $days[] = [
                'date' => $day->toDateString(),
                'conversations' => (int) ($row->total ?? 0),
                'out_of_hours' => (int) ($row->out_of_hours ?? 0),
            ];
        }

        return $days;
    }

    /**
     * Why the agent handed dialogs over, by the number of dialogs.
     *
     * @param  Builder<Conversation>  $conversationIds
     * @return list<array{reason: string, conversations: int}>
     */
    private function handoffReasons(Builder $conversationIds): array
    {
        // The agent names what the verifier caught in each such reason; for the statistics they are one reason.
        $reason = "case when action->>'reason' like ? then ? else action->>'reason' end";

        return $this->actions('handoff', $conversationIds)
            ->selectRaw("{$reason} as reason, count(distinct agent_runs.conversation_id) as conversations", [self::FAILED_CHECK.'%', self::FAILED_CHECK])
            ->groupBy('reason')
            ->orderByDesc('conversations')
            ->orderBy('reason')
            ->limit(self::LISTED_ROWS)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => ['reason' => $row->reason, 'conversations' => (int) $row->conversations])
            ->all();
    }

    /**
     * How the agent worked: dialogs it handed over, turns, replies replaced after the verifier, failures, speed.
     *
     * @param  Builder<Conversation>  $conversationIds
     * @return array{handed_off: int, turns: int, fallbacks: int, errors: int, seconds_median: float|null}
     */
    private function agent(Builder $conversationIds): array
    {
        $runs = AgentRun::query()
            ->whereIn('conversation_id', $conversationIds)
            ->selectRaw("count(distinct conversation_id) filter (where actions @> '[{\"type\": \"handoff\"}]') as handed_off")
            ->selectRaw('count(*) filter (where error is null) as turns')
            ->selectRaw('count(*) filter (where error is null and not verified) as fallbacks')
            ->selectRaw('count(*) filter (where error is not null) as errors')
            ->selectRaw('percentile_cont(0.5) within group (order by duration_ms) filter (where error is null) as median_ms')
            ->toBase()
            ->first();

        return [
            'handed_off' => (int) $runs->handed_off,
            'turns' => (int) $runs->turns,
            'fallbacks' => (int) $runs->fallbacks,
            'errors' => (int) $runs->errors,
            'seconds_median' => $runs->median_ms === null ? null : round($runs->median_ms / 1000, 1),
        ];
    }

    /**
     * What buyers searched for and found nothing, grouped by the conditions that matter to the sales department:
     * «трёшки до 12 млн в Тёплых холмах ищут в 5 диалогах, а их нет».
     *
     * @param  Builder<Conversation>  $conversationIds
     * @return list<array{filters: array<string, mixed>, conversations: int, searches: int}>
     */
    private function unmetDemand(Builder $conversationIds): array
    {
        return AgentRun::query()
            ->whereIn('conversation_id', $conversationIds)
            ->whereNotNull('filters')
            ->whereJsonContains('steps', [['tool' => ConversationResponder::SEARCH_TOOL, 'data' => ['total' => 0]]])
            ->get(['conversation_id', 'filters'])
            ->map(fn (AgentRun $run): array => ['conversation_id' => $run->conversation_id, 'filters' => $this->demand($run->filters)])
            ->reject(fn (array $search): bool => $search['filters'] === [])
            ->groupBy(fn (array $search): string => json_encode($search['filters']))
            ->map(fn (Collection $searches): array => [
                'filters' => $searches->first()['filters'],
                'conversations' => $searches->unique('conversation_id')->count(),
                'searches' => $searches->count(),
            ])
            ->sortByDesc(fn (array $row): array => [$row['conversations'], $row['searches']])
            ->take(self::LISTED_ROWS)
            ->values()
            ->all();
    }

    /**
     * The conditions of a search that describe demand: where, what layout, how much, how soon, what around.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function demand(array $filters): array
    {
        $sorted = fn (string $name): array => collect($filters[$name] ?? [])->sort()->values()->all();
        $priceTo = $filters['price_to'] ?? null;
        $deadlineTo = $filters['deadline_to'] ?? null;

        return array_filter([
            'complex_ids' => $sorted('complex_ids'),
            'rooms' => $sorted('rooms'),
            'is_euro' => $filters['is_euro'] ?? null,
            'price_to' => $priceTo === null ? null : (int) (ceil($priceTo / self::PRICE_STEP) * self::PRICE_STEP),
            'deadline_to' => $deadlineTo === null ? null : CarbonImmutable::parse($deadlineTo)->endOfYear()->toDateString(),
            'metro_walk_to' => $filters['metro_walk_to'] ?? null,
            'nearby' => $sorted('nearby'),
            'finishings' => $sorted('finishings'),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * Questions the knowledge base had no answer to, the most asked first: what to add to it.
     *
     * @param  Builder<Conversation>  $conversationIds
     * @return list<array{question: string, times: int, last_asked_at: string}>
     */
    private function knowledgeGaps(Builder $conversationIds): array
    {
        return $this->actions('knowledge_gap', $conversationIds)
            ->selectRaw("action->>'reason' as question, count(*) as times, max(agent_runs.created_at) as last_asked_at")
            ->groupBy('question')
            ->orderByDesc('times')
            ->orderByDesc('last_asked_at')
            ->limit(self::LISTED_ROWS)
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'question' => $row->question,
                'times' => (int) $row->times,
                'last_asked_at' => CarbonImmutable::parse($row->last_asked_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The agent's actions of one type in the dialogs, one row per action as `action`.
     *
     * @param  Builder<Conversation>  $conversationIds
     * @return Builder<AgentRun>
     */
    private function actions(string $type, Builder $conversationIds): Builder
    {
        return AgentRun::query()
            ->fromRaw('agent_runs, jsonb_array_elements(agent_runs.actions) as action')
            ->whereIn('agent_runs.conversation_id', $conversationIds)
            ->where(DB::raw("action->>'type'"), $type);
    }

    /**
     * An SQL condition: the moment in the column falls outside the office hours, in the office's time.
     */
    private function outOfHours(string $column): string
    {
        $time = "({$column} at time zone 'UTC' at time zone ?)::time";

        return "({$time} < ?::time or {$time} >= ?::time)";
    }

    /**
     * @return list<string>
     */
    private function officeHours(): array
    {
        $timezone = config('sales.timezone');

        return [$timezone, config('sales.opens_at'), $timezone, config('sales.closes_at')];
    }
}
