<?php

use App\Models\AgentRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Statistics\SalesStatistics;
use Carbon\CarbonImmutable;

/**
 * A Telegram dialog the buyer started, with the buyer's first message.
 */
function buyerDialog(Tenant $tenant, ?CarbonImmutable $at = null): Conversation
{
    $at ??= CarbonImmutable::now()->subDay();
    $conversation = Conversation::factory()->withContact(Contact::factory()->for($tenant)->create())->create(['created_at' => $at]);
    Message::factory()->for($conversation)->create(['created_at' => $at]);

    return $conversation;
}

/**
 * @return array<string, mixed>
 */
function statisticsOf(Tenant $tenant, int $days = 30): array
{
    return $tenant->run(fn (): array => app(SalesStatistics::class)->report(CarbonImmutable::now()->subDays($days)));
}

/**
 * @param  array<string, mixed>  $filters
 * @return array<string, mixed>
 */
function searchRun(Conversation $conversation, array $filters, int $total): array
{
    return [
        'conversation_id' => $conversation->id,
        'filters' => $filters,
        'steps' => [['tool' => 'search_flats', 'args' => ['request' => 'трёшка'], 'data' => ['total' => $total, 'flats' => []]]],
    ];
}

it('counts the dialogs buyers started in the period, their leads by score and the viewing requests', function () {
    $tenant = Tenant::factory()->create();
    Lead::factory()->hot()->for(buyerDialog($tenant))->create();
    Lead::factory()->for(buyerDialog($tenant))->create();
    buyerDialog($tenant);
    $testChat = Conversation::factory()->for($tenant)->create();
    Message::factory()->for($testChat)->create();
    Lead::factory()->hot()->for($testChat)->create();
    buyerDialog($tenant, CarbonImmutable::now()->subDays(40));
    Conversation::factory()->withContact(Contact::factory()->for($tenant)->create())->create();
    Lead::factory()->hot()->for(buyerDialog(Tenant::factory()->create()))->create();

    $funnel = statisticsOf($tenant)['funnel'];

    expect($funnel)->toMatchArray([
        'conversations' => 3,
        'leads' => 2,
        'scores' => ['hot' => 1, 'warm' => 1, 'cold' => 0],
        'viewing_requests' => 1,
    ]);
});

it('counts the dialogs started out of office hours, in the office\'s time, day by day', function () {
    config(['sales.timezone' => 'Europe/Moscow', 'sales.opens_at' => '09:00', 'sales.closes_at' => '21:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-25 12:00:00', 'UTC'));
    $tenant = Tenant::factory()->create();
    foreach (['05:59', '06:00', '17:59', '18:00'] as $utc) {
        buyerDialog($tenant, CarbonImmutable::parse("2026-10-24 {$utc}:00", 'UTC'));
    }
    buyerDialog($tenant, CarbonImmutable::parse('2026-10-23 21:30:00', 'UTC'));

    $statistics = statisticsOf($tenant, days: 7);

    expect($statistics['funnel']['out_of_hours'])->toBe(3)
        ->and(collect($statistics['days'])->pluck('date')->all())->toBe([
            '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25',
        ])
        ->and($statistics['days'][5])->toBe(['date' => '2026-10-23', 'conversations' => 0, 'out_of_hours' => 0])
        // 00:30 in Moscow on the 24th is a night dialog of that day.
        ->and($statistics['days'][6])->toBe(['date' => '2026-10-24', 'conversations' => 5, 'out_of_hours' => 3]);
});

it('tells why the agent handed dialogs over, one reason for every reply the verifier failed, and how the agent worked', function () {
    $tenant = Tenant::factory()->create();
    [$first, $second, $third] = [buyerDialog($tenant), buyerDialog($tenant), buyerDialog($tenant)];
    AgentRun::factory()->for($first)->create(['duration_ms' => 10_000, 'actions' => [['type' => 'handoff', 'reason' => 'запись на просмотр']]]);
    AgentRun::factory()->for($second)->create(['duration_ms' => 20_000, 'actions' => [['type' => 'handoff', 'reason' => 'запись на просмотр']]]);
    AgentRun::factory()->for($second)->create(['duration_ms' => 30_000, 'verified' => false, 'actions' => [['type' => 'handoff', 'reason' => 'ответ не прошёл проверку: сумма «7,9 млн ₽» не из результатов инструментов']]]);
    AgentRun::factory()->for($third)->create(['duration_ms' => 40_000, 'verified' => false, 'actions' => [['type' => 'handoff', 'reason' => 'ответ не прошёл проверку: квартиры № 509 нет в результатах инструментов']]]);
    AgentRun::factory()->for($third)->create(['duration_ms' => 50_000, 'actions' => [['type' => 'knowledge_gap', 'reason' => 'есть бассейн?']]]);
    AgentRun::factory()->for($third)->create(['duration_ms' => 120_000, 'verified' => false, 'error' => 'HTTP 503', 'actions' => [['type' => 'handoff', 'reason' => 'сервис ИИ недоступен']]]);
    AgentRun::factory()->for(buyerDialog(Tenant::factory()->create()))->create(['actions' => [['type' => 'handoff', 'reason' => 'запись на просмотр']]]);

    $statistics = statisticsOf($tenant);

    expect($statistics['handoff_reasons'])->toBe([
        ['reason' => 'запись на просмотр', 'conversations' => 2],
        ['reason' => 'ответ не прошёл проверку', 'conversations' => 2],
        ['reason' => 'сервис ИИ недоступен', 'conversations' => 1],
    ])->and($statistics['agent'])->toBe([
        'handed_off' => 3,
        'turns' => 5,
        'fallbacks' => 2,
        'errors' => 1,
        'seconds_median' => 30.0,
    ]);
});

it('groups the searches that found nothing by what buyers asked for, the most wanted first', function () {
    $tenant = Tenant::factory()->create();
    [$first, $second] = [buyerDialog($tenant), buyerDialog($tenant)];
    $threeRooms = ['rooms' => [3], 'price_to' => 12_000_000, 'statuses' => ['available'], 'not_first_floor' => false, 'nearby' => []];
    AgentRun::factory()->create(searchRun($first, $threeRooms, 0));
    AgentRun::factory()->create(searchRun($first, [...$threeRooms, 'price_to' => 11_500_000], 0));
    AgentRun::factory()->create(searchRun($second, $threeRooms, 0));
    AgentRun::factory()->create(searchRun($second, ['complex_ids' => [7], 'rooms' => [2], 'deadline_to' => '2026-06-30'], 0));
    AgentRun::factory()->create(searchRun($second, ['rooms' => [2], 'price_to' => 9_000_000], 14));
    AgentRun::factory()->create(searchRun(buyerDialog(Tenant::factory()->create()), $threeRooms, 0));

    expect(statisticsOf($tenant)['unmet_demand'])->toBe([
        ['filters' => ['rooms' => [3], 'price_to' => 12_000_000], 'conversations' => 2, 'searches' => 3],
        ['filters' => ['complex_ids' => [7], 'rooms' => [2], 'deadline_to' => '2026-12-31'], 'conversations' => 1, 'searches' => 1],
    ]);
});

it('lists the questions the knowledge base had no answer to, the most asked first', function () {
    $tenant = Tenant::factory()->create();
    $conversation = buyerDialog($tenant);
    $gap = fn (string $question, string $at) => AgentRun::factory()->for($conversation)->create([
        'actions' => [['type' => 'knowledge_gap', 'reason' => $question]],
        'created_at' => $at,
    ]);
    $gap('есть бассейн?', '2026-10-20 10:00:00');
    $gap('можно газовую плиту?', '2026-10-21 10:00:00');
    $gap('есть бассейн?', '2026-10-22 10:00:00');
    $this->travelTo('2026-10-25 12:00:00');

    expect(statisticsOf($tenant)['knowledge_gaps'])->toBe([
        ['question' => 'есть бассейн?', 'times' => 2, 'last_asked_at' => '2026-10-22T10:00:00+00:00'],
        ['question' => 'можно газовую плиту?', 'times' => 1, 'last_asked_at' => '2026-10-21T10:00:00+00:00'],
    ]);
});
