<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { filterChips } from '@/lib/search';
import type { SalesStatistics } from '@/types/statistics';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    days: number;
    periods: number[];
    office_hours: { opens_at: string; closes_at: string };
    complexes: Record<number, string>;
    statistics: SalesStatistics;
}>();

const number = new Intl.NumberFormat('ru-RU');
const dayLabel = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' });
const askedAt = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

const funnel = computed(() => props.statistics.funnel);
const qualified = computed(() => funnel.value.scores.hot + funnel.value.scores.warm);

function share(part: number, whole: number): string {
    return whole === 0 ? '—' : `${Math.round((part / whole) * 100)}%`;
}

function plural(count: number, one: string, few: string, many: string): string {
    const lastTwo = count % 100;
    const last = count % 10;

    if (lastTwo >= 11 && lastTwo <= 14) {
        return many;
    }

    return last === 1 ? one : last >= 2 && last <= 4 ? few : many;
}

// Each step of the funnel against the first, with the share of the step before it.
const steps = computed(() => {
    const items = [
        { label: 'Обращения', count: funnel.value.conversations },
        { label: 'Лиды', count: funnel.value.leads },
        { label: 'Горячие и тёплые', count: qualified.value },
        { label: 'Просили просмотр', count: funnel.value.viewing_requests },
    ];

    return items.map((item, index) => ({
        ...item,
        width: funnel.value.conversations === 0 ? 0 : (item.count / funnel.value.conversations) * 100,
        fromPrevious: index === 0 ? null : share(item.count, items[index - 1]?.count ?? 0),
    }));
});

const busiestDay = computed(() => Math.max(1, ...props.statistics.days.map((day) => day.conversations)));
const hovered = ref<number | null>(null);
const hoveredDay = computed(() => (hovered.value === null ? null : props.statistics.days[hovered.value]));

// Label every week, so that a 90-day period stays readable.
function showsDate(index: number): boolean {
    return (props.statistics.days.length - 1 - index) % 7 === 0;
}

const busiestReason = computed(() => Math.max(1, ...props.statistics.handoff_reasons.map((row) => row.conversations)));
</script>

<template>
    <Head title="Статистика" />
    <div class="flex flex-col gap-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-[25px] font-bold">Статистика</h1>
                <p class="text-[14px] text-muted">Диалоги покупателей в Telegram, без тестового чата.</p>
            </div>
            <nav class="flex gap-2" aria-label="Период">
                <Link
                    v-for="period in periods"
                    :key="period"
                    :href="period === 30 ? '/statistics' : `/statistics?days=${period}`"
                    preserve-scroll
                    class="rounded-[46px] px-3 py-1 text-[14px] transition-colors duration-150"
                    :class="period === days ? 'bg-accent text-ink' : 'bg-capsule text-muted hover:text-ink'"
                >
                    {{ period }} дней
                </Link>
            </nav>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Главное за период">
            <div class="flex flex-col gap-1 rounded-[24px] border border-line p-4">
                <span class="text-[14px] text-muted">Обращения</span>
                <span class="text-[25px] font-bold">{{ number.format(funnel.conversations) }}</span>
            </div>
            <div class="flex flex-col gap-1 rounded-[24px] border border-line p-4">
                <span class="text-[14px] text-muted">Вне рабочих часов</span>
                <span class="text-[25px] font-bold">{{ share(funnel.out_of_hours, funnel.conversations) }}</span>
                <span class="text-[12px] text-muted">
                    {{ number.format(funnel.out_of_hours) }} — офис открыт с {{ office_hours.opens_at }} до {{ office_hours.closes_at }}
                </span>
            </div>
            <div class="flex flex-col gap-1 rounded-[24px] border border-line p-4">
                <span class="text-[14px] text-muted">Горячие и тёплые лиды</span>
                <span class="text-[25px] font-bold">{{ number.format(qualified) }}</span>
                <span class="text-[12px] text-muted">горячих {{ funnel.scores.hot }}, тёплых {{ funnel.scores.warm }}, холодных {{ funnel.scores.cold }}</span>
            </div>
            <div class="flex flex-col gap-1 rounded-[24px] border border-line p-4">
                <span class="text-[14px] text-muted">Дошли до просьбы о просмотре</span>
                <span class="text-[25px] font-bold">{{ share(funnel.viewing_requests, funnel.conversations) }}</span>
                <span class="text-[12px] text-muted">{{ number.format(funnel.viewing_requests) }} из {{ number.format(funnel.conversations) }} обращений</span>
            </div>
        </section>

        <section class="flex flex-col gap-4 rounded-[24px] border border-line p-4" aria-labelledby="funnel-title">
            <div class="flex flex-col gap-1">
                <h2 id="funnel-title" class="text-[16px] font-bold">Воронка</h2>
                <p class="text-[14px] text-muted">Записи и визиты на просмотр появятся вместе с календарём офиса продаж.</p>
            </div>
            <ol class="flex flex-col gap-3">
                <li v-for="step in steps" :key="step.label" class="grid grid-cols-[160px_1fr] items-center gap-3">
                    <span class="text-[14px]">{{ step.label }}</span>
                    <span class="flex items-center gap-2">
                        <span class="h-6 rounded-r-[4px] bg-accent" :style="{ width: `${step.width}%` }" />
                        <span class="shrink-0 text-[14px] font-bold">{{ number.format(step.count) }}</span>
                        <span v-if="step.fromPrevious" class="shrink-0 text-[12px] text-muted">{{ step.fromPrevious }} от предыдущего</span>
                    </span>
                </li>
            </ol>
        </section>

        <section class="flex flex-col gap-4 rounded-[24px] border border-line p-4" aria-labelledby="days-title">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 id="days-title" class="text-[16px] font-bold">Обращения по дням</h2>
                <div class="flex gap-4 text-[12px] text-muted">
                    <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-accent" />в рабочие часы</span>
                    <span class="flex items-center gap-1"><span class="size-2 rounded-full bg-lavender" />вне рабочих часов</span>
                </div>
            </div>
            <div class="relative">
                <p
                    v-if="hoveredDay"
                    class="pointer-events-none absolute top-0 right-0 rounded-[12px] border border-line bg-capsule px-3 py-2 text-[12px] shadow-raised"
                >
                    <span class="font-bold">{{ dayLabel.format(new Date(hoveredDay.date)) }}</span> · обращений {{ hoveredDay.conversations }}, вне
                    рабочих часов {{ hoveredDay.out_of_hours }}
                </p>
                <div class="flex h-40 items-end gap-[2px] border-b border-chip pt-10" @mouseleave="hovered = null">
                    <button
                        v-for="(day, index) in statistics.days"
                        :key="day.date"
                        type="button"
                        class="flex h-full flex-1 justify-center focus-visible:outline-2 focus-visible:outline-accent"
                        :aria-label="`${dayLabel.format(new Date(day.date))}: обращений ${day.conversations}, вне рабочих часов ${day.out_of_hours}`"
                        @mouseenter="hovered = index"
                        @focus="hovered = index"
                        @blur="hovered = null"
                    >
                        <span class="flex h-full w-full max-w-6 flex-col justify-end gap-[2px]">
                        <span
                            v-if="day.out_of_hours > 0"
                            class="w-full rounded-t-[4px] bg-lavender"
                            :style="{ height: `${(day.out_of_hours / busiestDay) * 100}%` }"
                        />
                        <span
                            v-if="day.conversations > day.out_of_hours"
                            class="w-full bg-accent"
                            :class="{ 'rounded-t-[4px]': day.out_of_hours === 0 }"
                            :style="{ height: `${((day.conversations - day.out_of_hours) / busiestDay) * 100}%` }"
                        />
                        </span>
                    </button>
                </div>
                <div class="flex gap-[2px] pt-1">
                    <span v-for="(day, index) in statistics.days" :key="day.date" class="relative h-4 flex-1">
                        <span v-if="showsDate(index)" class="absolute text-[12px] whitespace-nowrap text-muted"
                            :class="index === statistics.days.length - 1 ? 'right-0' : 'left-1/2 -translate-x-1/2'">
                            {{ dayLabel.format(new Date(day.date)) }}
                        </span>
                    </span>
                </div>
            </div>
            <details class="pt-4 text-[14px]">
                <summary class="cursor-pointer text-muted">Таблица по дням</summary>
                <table class="mt-2 w-full text-left">
                    <thead class="text-[12px] text-muted">
                        <tr class="border-b border-chip">
                            <th class="py-1 font-normal">День</th>
                            <th class="py-1 font-normal">Обращения</th>
                            <th class="py-1 font-normal">Вне рабочих часов</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="day in statistics.days" :key="day.date" class="border-b border-chip">
                            <td class="py-1">{{ dayLabel.format(new Date(day.date)) }}</td>
                            <td class="py-1">{{ day.conversations }}</td>
                            <td class="py-1">{{ day.out_of_hours }}</td>
                        </tr>
                    </tbody>
                </table>
            </details>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="flex flex-col gap-4 rounded-[24px] border border-line p-4" aria-labelledby="demand-title">
                <div class="flex flex-col gap-1">
                    <h2 id="demand-title" class="text-[16px] font-bold">Ищут, а квартир нет</h2>
                    <p class="text-[14px] text-muted">Поиски, по которым ИИ ничего не нашёл: подсказка для отдела продаж.</p>
                </div>
                <p v-if="statistics.unmet_demand.length === 0" class="text-[14px] text-muted">За период все поиски что-то нашли.</p>
                <ul class="flex flex-col gap-3">
                    <li v-for="row in statistics.unmet_demand" :key="JSON.stringify(row.filters)" class="flex items-start justify-between gap-3">
                        <span class="flex flex-wrap gap-1">
                            <span v-for="chip in filterChips(row.filters, complexes)" :key="chip" class="rounded-[46px] bg-capsule px-3 py-1 text-[14px]">
                                {{ chip }}
                            </span>
                        </span>
                        <span class="shrink-0 pt-1 text-[14px] text-muted">
                            {{ row.conversations }} {{ plural(row.conversations, 'диалог', 'диалога', 'диалогов') }}
                        </span>
                    </li>
                </ul>
            </section>

            <section class="flex flex-col gap-4 rounded-[24px] border border-line p-4" aria-labelledby="handoffs-title">
                <div class="flex flex-col gap-1">
                    <h2 id="handoffs-title" class="text-[16px] font-bold">Почему ИИ передаёт менеджеру</h2>
                    <p class="text-[14px] text-muted">
                        Передано {{ statistics.agent.handed_off }} из {{ funnel.conversations }} диалогов ({{
                            share(statistics.agent.handed_off, funnel.conversations)
                        }}).
                    </p>
                </div>
                <p v-if="statistics.handoff_reasons.length === 0" class="text-[14px] text-muted">За период ИИ никому не передавал диалоги.</p>
                <ul class="flex flex-col gap-2">
                    <li v-for="row in statistics.handoff_reasons" :key="row.reason" class="flex flex-col gap-1">
                        <span class="flex justify-between gap-3 text-[14px]">
                            <span>{{ row.reason }}</span>
                            <span class="shrink-0 text-muted">{{ row.conversations }}</span>
                        </span>
                        <span class="h-2 rounded-r-[4px] bg-accent" :style="{ width: `${(row.conversations / busiestReason) * 100}%` }" />
                    </li>
                </ul>
            </section>

            <section class="flex flex-col gap-4 rounded-[24px] border border-line p-4" aria-labelledby="gaps-title">
                <div class="flex flex-col gap-1">
                    <h2 id="gaps-title" class="text-[16px] font-bold">Нет ответа в базе знаний</h2>
                    <p class="text-[14px] text-muted">ИИ ответил «уточню у менеджера»: эти вопросы стоит добавить в базу.</p>
                </div>
                <p v-if="statistics.knowledge_gaps.length === 0" class="text-[14px] text-muted">База знаний ответила на все вопросы.</p>
                <ul class="flex flex-col gap-2">
                    <li v-for="row in statistics.knowledge_gaps" :key="row.question" class="flex justify-between gap-3 text-[14px]">
                        <span>{{ row.question }}</span>
                        <span class="shrink-0 text-muted">
                            {{ row.times }} {{ plural(row.times, 'раз', 'раза', 'раз') }} · {{ askedAt.format(new Date(row.last_asked_at)) }}
                        </span>
                    </li>
                </ul>
            </section>

            <section class="flex flex-col gap-4 rounded-[24px] border border-line p-4" aria-labelledby="agent-title">
                <h2 id="agent-title" class="text-[16px] font-bold">Работа ИИ</h2>
                <dl class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col gap-1">
                        <dt class="text-[14px] text-muted">Ответов</dt>
                        <dd class="text-[16px] font-bold">{{ number.format(statistics.agent.turns) }}</dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="text-[14px] text-muted">Время ответа, медиана</dt>
                        <dd class="text-[16px] font-bold">{{ statistics.agent.seconds_median === null ? '—' : `${statistics.agent.seconds_median} с` }}</dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="text-[14px] text-muted">Не прошли проверку фактов</dt>
                        <dd class="text-[16px] font-bold">{{ share(statistics.agent.fallbacks, statistics.agent.turns) }}</dd>
                    </div>
                    <div class="flex flex-col gap-1">
                        <dt class="text-[14px] text-muted">Сервис ИИ не ответил</dt>
                        <dd class="text-[16px] font-bold">{{ statistics.agent.errors }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
</template>
