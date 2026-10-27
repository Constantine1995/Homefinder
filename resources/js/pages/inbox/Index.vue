<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { onConversationUpdated } from '@/lib/conversations';
import { formatMillions } from '@/lib/format';
import type { InboxConversation, InboxConversationSummary, InboxFilter, InboxMessage, Lead, LeadScore } from '@/types/chat';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    filter: InboxFilter;
    counts: { waiting: number; mine: number };
    conversations: InboxConversationSummary[];
    conversation: InboxConversation | null;
    messages: InboxMessage[];
    waiting: boolean;
}>();

const form = useForm({ text: '' });
const thread = ref<HTMLElement | null>(null);

const filters: { value: InboxFilter; label: string; count?: 'waiting' | 'mine' }[] = [
    { value: 'open', label: 'Открытые' },
    { value: 'waiting', label: 'Ждут менеджера', count: 'waiting' },
    { value: 'mine', label: 'Мои', count: 'mine' },
    { value: 'closed', label: 'Закрытые' },
];
const channels: Record<InboxConversation['channel'], string> = { telegram: 'Telegram', widget: 'Сайт', test: 'Тестовый чат' };
const scores: Record<LeadScore, string> = { hot: 'Горячий', warm: 'Тёплый', cold: 'Холодный' };
const payments: Record<NonNullable<Lead['payment']>, string> = { mortgage: 'ипотека', cash: 'наличные', installment: 'рассрочка' };
const clock = new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' });
const day = new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' });

const contactLine = computed(() => {
    const contact = props.conversation?.contact;

    return [contact?.username ? `@${contact.username}` : null, contact?.phone, channels[props.conversation?.channel ?? 'test']]
        .filter((part) => part)
        .join(' · ');
});

// What the buyer told, in the words of a sales office: «до 9 млн ₽», «2-комн., 3-комн.», «сдача до 2027».
const leadFacts = computed(() => {
    const lead = props.conversation?.lead;

    if (!lead) {
        return [];
    }

    const rooms = lead.rooms.map((count) => (count === 0 ? 'студия' : count >= 4 ? '4+ комн.' : `${count}-комн.`)).join(', ');

    return [
        lead.budget === null ? null : `до ${formatMillions(lead.budget)}`,
        lead.payment === null ? null : payments[lead.payment],
        rooms === '' ? null : rooms,
        lead.deadline_year === null ? null : `сдача до ${lead.deadline_year}`,
        lead.family === null ? null : `семья: ${lead.family}`,
        lead.sells_own_flat === null ? null : lead.sells_own_flat ? 'продаёт своё жильё' : 'своё жильё не продаёт',
        lead.wants_viewing ? 'хочет на просмотр' : null,
    ].filter((fact): fact is string => fact !== null);
});

// Reverb says which conversation changed; a burst of changes (the buyer's message, the agent's reply, its handoff)
// becomes one reload of what the page shows.
const pending = new Set<string>();
let reloadTimer: ReturnType<typeof setTimeout> | undefined;

onConversationUpdated((conversationId) => {
    ['conversations', 'counts'].forEach((prop) => pending.add(prop));

    if (conversationId === props.conversation?.id) {
        ['conversation', 'messages', 'waiting'].forEach((prop) => pending.add(prop));
    }

    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(() => {
        router.reload({ only: [...pending] });
        pending.clear();
    }, 150);
});

watch(
    () => props.messages.length,
    () => scrollToEnd(),
);

onMounted(scrollToEnd);

function scrollToEnd(): void {
    nextTick(() => thread.value?.scrollTo({ top: thread.value.scrollHeight }));
}

function href(filter: InboxFilter, conversationId?: number): string {
    const path = conversationId === undefined ? '/inbox' : `/inbox/${conversationId}`;

    return filter === 'open' ? path : `${path}?filter=${filter}`;
}

function when(at: string | null): string {
    if (at === null) {
        return '';
    }

    const date = new Date(at);

    return date.toDateString() === new Date().toDateString() ? clock.format(date) : day.format(date);
}

function statusOf(item: InboxConversationSummary): string {
    if (item.status === 'ai') {
        return 'отвечает ИИ';
    }

    if (item.status === 'closed') {
        return 'закрыт';
    }

    return item.assignee === null ? 'ждёт менеджера' : `у менеджера ${item.assignee}`;
}

function author(message: InboxMessage): string {
    if (message.role === 'buyer') {
        return 'Покупатель';
    }

    return message.role === 'agent' ? 'ИИ-менеджер' : (message.author ?? 'Менеджер');
}

function act(action: 'take' | 'hand-back' | 'close'): void {
    if (props.conversation) {
        router.post(`/inbox/${props.conversation.id}/${action}`, {}, { preserveScroll: true });
    }
}

function send(): void {
    if (!props.conversation || form.text.trim() === '') {
        return;
    }

    form.post(`/inbox/${props.conversation.id}/messages`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Диалоги" />
    <div class="grid gap-6 lg:grid-cols-[320px_1fr]">
        <aside class="flex flex-col gap-3">
            <nav class="flex flex-wrap gap-2" aria-label="Какие диалоги показать">
                <Link
                    v-for="item in filters"
                    :key="item.value"
                    :href="href(item.value, conversation?.id)"
                    class="rounded-[46px] px-3 py-1 text-[14px] transition-colors duration-150"
                    :class="item.value === filter ? 'bg-accent text-ink' : 'bg-capsule text-muted hover:text-ink'"
                >
                    {{ item.label }}<template v-if="item.count && counts[item.count] > 0"> · {{ counts[item.count] }}</template>
                </Link>
            </nav>

            <p v-if="conversations.length === 0" class="px-4 text-[14px] text-muted">Здесь пока нет диалогов.</p>
            <nav class="flex flex-col gap-1" aria-label="Диалоги">
                <Link
                    v-for="item in conversations"
                    :key="item.id"
                    :href="href(filter, item.id)"
                    preserve-scroll
                    class="flex flex-col gap-1 rounded-[24px] px-4 py-2 transition-colors duration-150"
                    :class="item.id === conversation?.id ? 'bg-capsule' : 'hover:bg-capsule'"
                >
                    <span class="flex items-baseline gap-2">
                        <span
                            v-if="item.status === 'human' && item.last_role === 'buyer'"
                            class="size-2 shrink-0 self-center rounded-full bg-accent"
                            title="Покупатель ждёт ответа"
                        />
                        <span class="truncate text-[14px] font-bold">{{ item.title }}</span>
                        <span class="ml-auto shrink-0 text-[12px] text-muted">{{ when(item.last_message_at) }}</span>
                    </span>
                    <span class="truncate text-[14px] text-muted">{{ item.preview }}</span>
                    <span class="text-[12px] text-muted">
                        {{ channels[item.channel] }} · {{ statusOf(item) }}<template v-if="item.score"> · {{ scores[item.score].toLowerCase() }} лид</template>
                    </span>
                </Link>
            </nav>
        </aside>

        <section v-if="conversation" class="flex min-h-[560px] flex-col gap-4 rounded-[24px] border border-line p-4">
            <header class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h1 class="text-[16px] font-bold">{{ conversation.title }}</h1>
                    <span class="text-[14px] text-muted">{{ contactLine }}</span>
                    <span class="text-[14px]">
                        <template v-if="conversation.status === 'ai'">Отвечает ИИ</template>
                        <template v-else-if="conversation.status === 'closed'">Диалог закрыт</template>
                        <template v-else-if="conversation.assignee">У менеджера {{ conversation.assignee.name }}</template>
                        <template v-else>Ждёт менеджера</template>
                        <span v-if="conversation.handoff_reason" class="text-muted"> · ИИ передал: {{ conversation.handoff_reason }}</span>
                    </span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="conversation.can.take" label="Забрать диалог" size="small" @click="act('take')" />
                    <Button
                        v-if="conversation.can.hand_back"
                        label="Вернуть ИИ"
                        severity="secondary"
                        variant="outlined"
                        size="small"
                        @click="act('hand-back')"
                    />
                    <Button v-if="conversation.can.close" label="Закрыть" severity="secondary" variant="outlined" size="small" @click="act('close')" />
                </div>
            </header>

            <section v-if="conversation.saved_searches.length" class="flex flex-wrap items-center gap-2" aria-label="Подписки">
                <span class="text-[14px] text-muted">Ждёт:</span>
                <span v-for="search in conversation.saved_searches" :key="search" class="rounded-[46px] border border-line px-3 py-1 text-[14px]">
                    {{ search }}
                </span>
            </section>

            <section v-if="conversation.lead" class="flex flex-wrap items-center gap-2" aria-label="Лид">
                <span
                    class="rounded-[46px] px-3 py-1 text-[14px]"
                    :class="{
                        'bg-accent': conversation.lead.score === 'hot',
                        'bg-chip': conversation.lead.score === 'warm',
                        'border border-line text-muted': conversation.lead.score === 'cold',
                    }"
                >
                    {{ scores[conversation.lead.score] }} лид
                </span>
                <span v-for="fact in leadFacts" :key="fact" class="rounded-[46px] bg-capsule px-3 py-1 text-[14px]">{{ fact }}</span>
            </section>

            <div ref="thread" class="flex max-h-[60vh] flex-1 flex-col gap-4 overflow-y-auto">
                <article
                    v-for="message in messages"
                    :key="message.id"
                    class="flex max-w-[85%] flex-col gap-1"
                    :class="message.role === 'buyer' ? 'self-start' : 'self-end items-end'"
                >
                    <span class="text-[12px] text-muted">
                        {{ author(message) }} · {{ clock.format(new Date(message.created_at)) }}
                        <template v-if="conversation.channel !== 'test' && message.role !== 'buyer'">
                            · {{ message.delivered_at ? 'доставлено' : 'отправляется' }}
                        </template>
                    </span>
                    <p
                        class="rounded-[24px] px-4 py-2 text-[14px] whitespace-pre-line"
                        :class="{
                            'border border-line': message.role === 'buyer',
                            'bg-capsule': message.role === 'agent',
                            'bg-accent': message.role === 'manager',
                        }"
                    >
                        {{ message.text }}
                    </p>
                </article>
                <p v-if="waiting" class="self-end text-[14px] text-muted motion-safe:animate-pulse">ИИ-менеджер отвечает…</p>
            </div>

            <form v-if="conversation.can.reply" class="flex flex-col gap-1" @submit.prevent="send">
                <div class="flex gap-2">
                    <InputText
                        v-model="form.text"
                        class="min-w-0 flex-1"
                        placeholder="Ответ покупателю"
                        aria-label="Ответ покупателю"
                        maxlength="4000"
                    />
                    <Button type="submit" label="Отправить" :loading="form.processing" />
                </div>
                <span v-if="form.errors.text" class="text-[14px] text-danger">{{ form.errors.text }}</span>
            </form>
            <p v-else class="text-[14px] text-muted">
                <template v-if="conversation.status === 'ai'">Покупателю отвечает ИИ. Заберите диалог, чтобы написать самому.</template>
                <template v-else-if="conversation.status === 'closed'">Если покупатель напишет снова, начнётся новый диалог.</template>
                <template v-else-if="conversation.assignee">Писать покупателю может только {{ conversation.assignee.name }}.</template>
                <template v-else>Заберите диалог, чтобы ответить покупателю.</template>
            </p>
        </section>

        <section v-else class="flex flex-col items-start gap-3 rounded-[24px] border border-line p-4">
            <h1 class="text-[25px] font-bold">Диалоги</h1>
            <p class="text-[14px] text-muted">
                Все диалоги покупателей в реальном времени. Выберите диалог слева: заберите его у ИИ, чтобы ответить самому, верните ИИ или
                закройте.
            </p>
        </section>
    </div>
</template>
