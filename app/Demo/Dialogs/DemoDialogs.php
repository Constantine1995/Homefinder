<?php

namespace App\Demo\Dialogs;

use App\Enums\ChannelType;
use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Events\ConversationUpdated;
use App\Models\Channel;
use App\Models\Complex;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A month of Telegram dialogs of the demo developer, for the statistics and the shared chat to have something to
 * show: buyers search, count mortgages, ask about the complexes, ask for viewings and discounts, search for what
 * the developer does not have. The dialogs are written as the agent would log them, without calling it.
 *
 * They belong to a switched-off channel of their own, so generating them again replaces them, and nothing is ever
 * sent to Telegram.
 */
class DemoDialogs
{
    /**
     * The external ID of the channel the generated dialogs belong to.
     */
    public const string CHANNEL_ID = 'demo-history';

    /**
     * Dialogs older than this many hours are closed, unless a manager has them.
     */
    public const int OPEN_HOURS = 24;

    /**
     * Searches that find flats: the buyer's words, the conditions the agent understood, the flats found.
     *
     * @var list<array{0: string, 1: array<string, mixed>, 2: int}>
     */
    private const array FOUND = [
        ['какие двушки есть в Тёплых холмах?', ['complex' => 'Тёплые холмы', 'rooms' => [2]], 95],
        ['студия до 9 млн', ['rooms' => [0], 'price_to' => 9_000_000], 63],
        ['однушка в Отрадном квартале', ['complex' => 'Отрадный квартал', 'rooms' => [1]], 50],
        ['евродвушка до 10 млн, сдача не позже 2027', ['rooms' => [2], 'is_euro' => true, 'price_to' => 10_000_000, 'deadline_to' => '2027-12-31'], 9],
        ['трёшка для семьи до 20 млн, рядом школа', ['rooms' => [3], 'price_to' => 20_000_000, 'nearby' => ['school']], 27],
        ['квартира с видом на реку в Речной террасе', ['complex' => 'Речная терраса'], 213],
    ];

    /**
     * Searches that find nothing: the demand the developer has no flats for, the most frequent first.
     *
     * @var list<array{0: string, 1: array<string, mixed>, 2: int}>
     */
    private const array UNMET = [
        ['трёшка до 12 млн', ['rooms' => [3], 'price_to' => 12_000_000], 6],
        ['двушка в Речной террасе до 25 млн', ['complex' => 'Речная терраса', 'rooms' => [2], 'price_to' => 25_000_000], 4],
        ['однушка до 8 млн, до метро пешком', ['rooms' => [1], 'price_to' => 8_000_000, 'metro_walk_to' => 15], 3],
        ['студия до 6 млн с ремонтом', ['rooms' => [0], 'price_to' => 6_000_000, 'finishings' => ['finished']], 2],
        ['четырёхкомнатная в Тёплых холмах', ['complex' => 'Тёплые холмы', 'rooms' => [4]], 1],
    ];

    /**
     * Questions the knowledge base answers.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array ANSWERED = [
        ['а паркинг там есть?', 'Да, в комплексе подземный паркинг, места продаются отдельно. Источник: «ЖК „Тёплые холмы“».'],
        ['где у вас офис продаж и до скольки работает?', 'Офис продаж — Сигнальный проезд, 19к1, ежедневно с 9:00 до 21:00. Источник: «О застройщике и офисе продаж».'],
        ['с какими банками работаете?', 'С банками «Северный» и «Столица», заявку можно подать сразу в оба через менеджера. Источник: «Ипотека, акции и банки-партнёры».'],
        ['деньги идут на эскроу?', 'Да, оплата только через эскроу-счёт: застройщик получит деньги после ввода дома. Источник: «Покупка квартиры: вопросы и ответы».'],
    ];

    /**
     * Questions the knowledge base has no answer to.
     *
     * @var list<string>
     */
    private const array GAPS = [
        'есть ли во дворе бассейн?',
        'можно поставить газовую плиту?',
        'какая будет управляющая компания?',
        'можно ли гулять с собакой на территории?',
        'сколько стоит коммуналка в месяц?',
    ];

    /**
     * What the buyer does after the agent found flats, with weights.
     *
     * @var array<string, int>
     */
    private const array NEXT_STEPS = [
        'leaves' => 26,
        'mortgage' => 22,
        'viewing' => 18,
        'knowledge' => 12,
        'gap' => 8,
        'discount' => 8,
        'trade_in' => 4,
        'phone_call' => 2,
    ];

    /**
     * When buyers write, by the office's hour: most write in the daytime, many in the evening, a few at night.
     *
     * @var array<int, int>
     */
    private const array HOURS = [
        0 => 2, 1 => 1, 2 => 1, 3 => 0, 4 => 0, 5 => 1, 6 => 2, 7 => 4, 8 => 6, 9 => 7, 10 => 8, 11 => 9,
        12 => 9, 13 => 9, 14 => 8, 15 => 8, 16 => 8, 17 => 8, 18 => 9, 19 => 10, 20 => 10, 21 => 9, 22 => 7, 23 => 4,
    ];

    private Randomizer $random;

    private CarbonImmutable $now;

    /**
     * @var array<string, int>
     */
    private array $complexIds;

    private ?User $manager;

    /**
     * Replace the developer's generated dialogs with new ones; the same seed writes the same dialogs.
     */
    public function generate(Tenant $tenant, int $count, int $days, int $seed = 1): int
    {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->now = CarbonImmutable::now();

        return $tenant->run(fn (): int => DB::transaction(function () use ($tenant, $count, $days): int {
            $this->complexIds = Complex::query()->pluck('id', 'name')->all();
            $this->manager = $tenant->users()->orderBy('id')->first();

            Channel::query()->where('external_id', self::CHANNEL_ID)->delete();
            $channel = Channel::query()->create([
                'type' => ChannelType::Telegram,
                'name' => '@homefinder_history_bot',
                'external_id' => self::CHANNEL_ID,
                'credentials' => ['token' => 'demo', 'webhook_secret' => 'demo'],
                'settings' => [],
                'is_active' => false,
            ]);

            // The cabinets are not told about history being written.
            Event::fakeFor(function () use ($channel, $count, $days): void {
                for ($number = 1; $number <= $count; $number++) {
                    $this->dialog($channel, $number, $this->startedAt($days));
                }
            }, [ConversationUpdated::class]);

            return $count;
        }));
    }

    private function dialog(Channel $channel, int $number, CarbonImmutable $at): void
    {
        $contact = Contact::query()->create([
            'channel_id' => $channel->id,
            'channel_user_id' => "demo-{$number}",
            'name' => $this->pick(['Анна', 'Иван', 'Мария', 'Дмитрий', 'Елена', 'Сергей', 'Ольга', 'Алексей', 'Наталья', 'Павел']),
            'consent_at' => $at,
        ]);
        $conversation = Conversation::query()->forceCreate([
            'contact_id' => $contact->id,
            'channel' => ConversationChannel::Telegram,
            'status' => ConversationStatus::Ai,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $dialog = new DemoDialog($conversation, $at, $this->random);

        $lead = [];
        if ($this->chance(3)) {
            $dialog->handOff('Уже второй месяц не могу получить ключи, никто не отвечает!', 'жалоба на задержку ключей');
        } elseif ($this->chance(3)) {
            $dialog->handOff('соедините с живым человеком', 'покупатель просит менеджера');
        } elseif ($this->chance(2)) {
            $dialog->unavailable('здравствуйте, есть двушки?');
        } elseif ($this->chance(2)) {
            $dialog->failedCheck('а за 7,9 млн двушка будет?');
        } elseif ($this->chance(8)) {
            $dialog->knowledge(...$this->pick(self::ANSWERED));
        } elseif ($this->chance(28)) {
            $lead = $this->unmet($dialog);
        } else {
            $lead = $this->found($dialog, $this->pick(self::FOUND));
        }

        $this->finish($conversation, $contact, $dialog, $lead);
    }

    /**
     * The buyer searches for what the developer lacks; some ask what there is instead.
     *
     * @return array<string, mixed>
     */
    private function unmet(DemoDialog $dialog): array
    {
        [$request, $conditions] = $this->weighted(self::UNMET);
        $dialog->search($request, $this->filters($conditions), 0);
        $lead = $this->leadOf($conditions);

        if ($this->chance(40)) {
            $dialog->search('а что есть за эти деньги?', $this->filters(['price_to' => $conditions['price_to'] ?? 20_000_000]), $this->random->getInt(3, 30));
        }

        return $lead;
    }

    /**
     * The buyer finds flats and takes one of the next steps.
     *
     * @param  array{0: string, 1: array<string, mixed>, 2: int}  $search
     * @return array<string, mixed>
     */
    private function found(DemoDialog $dialog, array $search): array
    {
        [$request, $conditions, $total] = $search;
        $dialog->search($request, $this->filters($conditions), $total);
        $lead = $this->leadOf($conditions);

        switch ($this->weightedKey(self::NEXT_STEPS)) {
            case 'mortgage':
                $dialog->mortgage();
                $lead['payment'] = 'mortgage';
                break;
            case 'viewing':
                $dialog->viewing();
                $lead['wants_viewing'] = true;
                $lead['phone'] = $this->chance(60);
                $lead['payment'] = $this->pick(['mortgage', 'mortgage', 'cash', null]);
                break;
            case 'knowledge':
                $dialog->knowledge(...$this->pick(self::ANSWERED));
                break;
            case 'gap':
                $dialog->gap($this->pick(self::GAPS));
                break;
            case 'discount':
                $dialog->handOff('а скидку сделаете, если сразу оплачу?', 'покупатель просит скидку');
                $lead['payment'] = 'cash';
                break;
            case 'trade_in':
                $dialog->handOff('можно сдать мою однушку в зачёт?', 'трейд-ин');
                $lead['sells_own_flat'] = true;
                break;
            case 'phone_call':
                $dialog->handOff('позвоните мне, пожалуйста', 'покупатель просит позвонить');
                $lead['phone'] = true;
                break;
        }

        return $lead;
    }

    /**
     * Store the lead and leave the dialog as the managers would: handed-over dialogs taken, old ones closed.
     *
     * @param  array<string, mixed>  $lead
     */
    private function finish(Conversation $conversation, Contact $contact, DemoDialog $dialog, array $lead): void
    {
        if ($lead['phone'] ?? false) {
            $contact->update(['phone' => '+7916'.$this->random->getInt(1_000_000, 9_999_999)]);
        }
        unset($lead['phone']);

        if ($lead !== []) {
            Lead::query()->forceCreate([
                ...$lead,
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'created_at' => $dialog->startedAt,
                'updated_at' => $dialog->lastAt,
            ]);
        }

        $old = $dialog->lastAt->diffInHours($this->now) > self::OPEN_HOURS;
        $conversation->forceFill([
            'status' => match (true) {
                $old => ConversationStatus::Closed,
                $dialog->handoffReason !== null => ConversationStatus::Human,
                default => ConversationStatus::Ai,
            },
            'handoff_reason' => $dialog->handoffReason,
            'assignee_id' => $dialog->handoffReason !== null && $old ? $this->manager?->id : null,
            'last_message_at' => $dialog->lastAt,
            'updated_at' => $dialog->lastAt,
        ])->save();
    }

    /**
     * The lead card the agent would collect from a search.
     *
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    private function leadOf(array $conditions): array
    {
        return array_filter([
            'budget' => $conditions['price_to'] ?? null,
            'rooms' => $conditions['rooms'] ?? null,
            'deadline_year' => isset($conditions['deadline_to']) ? (int) substr($conditions['deadline_to'], 0, 4) : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The search conditions as the agent's dialog state keeps them.
     *
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    private function filters(array $conditions): array
    {
        $complex = $conditions['complex'] ?? null;
        unset($conditions['complex']);

        return [
            'complex_ids' => $complex === null ? [] : [$this->complexIds[$complex]],
            ...$conditions,
        ];
    }

    private function startedAt(int $days): CarbonImmutable
    {
        $timezone = config('sales.timezone');
        $day = $this->now->setTimezone($timezone)->startOfDay()->subDays($this->random->getInt(0, $days - 1));
        $at = $day->setTime($this->weightedKey(self::HOURS), $this->random->getInt(0, 59))->utc();
        // Drawn every time, so that the dialogs after it do not depend on the time of day of the run.
        $minutesAgo = $this->random->getInt(10, 120);

        return $at->isFuture() ? $this->now->subMinutes($minutesAgo) : $at;
    }

    private function chance(int $percent): bool
    {
        return $this->random->getInt(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[$this->random->getInt(0, count($items) - 1)];
    }

    /**
     * An item of a list whose last element is its weight.
     *
     * @param  list<array<int, mixed>>  $items
     * @return array<int, mixed>
     */
    private function weighted(array $items): array
    {
        return $items[$this->weightedKey(array_map(fn (array $item): int => end($item), $items))];
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $weights
     * @return TKey
     */
    private function weightedKey(array $weights): int|string
    {
        $roll = $this->random->getInt(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
