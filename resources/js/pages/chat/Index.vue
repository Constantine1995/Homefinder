<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import { nextTick, onMounted, ref, watch } from 'vue';
import AgentTurn from '@/components/chat/AgentTurn.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { onConversationUpdated } from '@/lib/conversations';
import type { ChatMessage, Conversation, ConversationSummary } from '@/types/chat';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    examples: string[];
    conversations: ConversationSummary[];
    conversation: Conversation | null;
    messages: ChatMessage[];
    waiting: boolean;
}>();

const form = useForm({ text: '' });
const thread = ref<HTMLElement | null>(null);

const roles: Record<ChatMessage['role'], string> = { buyer: 'Покупатель', agent: 'ИИ-менеджер', manager: 'Менеджер' };
const time = new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' });

// The agent answers from the queue, and a manager may take the dialog in the shared chat: Reverb tells the page when.
onConversationUpdated((conversationId) => {
    if (conversationId === props.conversation?.id) {
        router.reload({ only: ['messages', 'waiting', 'conversation', 'conversations'] });
    } else if (props.conversations.some((item) => item.id === conversationId)) {
        router.reload({ only: ['conversations'] });
    }
});

watch(
    () => props.messages.length,
    () => scrollToEnd(),
);

onMounted(scrollToEnd);

function scrollToEnd(): void {
    nextTick(() => thread.value?.scrollTo({ top: thread.value.scrollHeight }));
}

function startConversation(): void {
    router.post('/chat');
}

function send(text = form.text): void {
    if (!props.conversation || text.trim() === '') {
        return;
    }

    form.text = text;
    form.post(`/chat/${props.conversation.id}/messages`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function unsubscribe(savedSearchId: number): void {
    if (props.conversation) {
        router.post(`/chat/${props.conversation.id}/saved-searches/${savedSearchId}/unsubscribe`, {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Тестовый чат" />
    <div class="grid gap-6 lg:grid-cols-[280px_1fr]">
        <aside class="flex flex-col gap-3">
            <Button label="Новый диалог" @click="startConversation" />
            <nav class="flex flex-col gap-1" aria-label="Тестовые диалоги">
                <Link
                    v-for="item in conversations"
                    :key="item.id"
                    :href="`/chat/${item.id}`"
                    class="flex flex-col rounded-[24px] px-4 py-2 transition-colors duration-150"
                    :class="item.id === conversation?.id ? 'bg-capsule' : 'hover:bg-capsule'"
                >
                    <span class="truncate text-[14px]">{{ item.title ?? 'Пустой диалог' }}</span>
                    <span class="text-[12px] text-muted">
                        №{{ item.id }} · {{ item.status === 'ai' ? 'отвечает ИИ' : item.status === 'human' ? 'у менеджера' : 'закрыт' }}
                    </span>
                </Link>
            </nav>
        </aside>

        <section v-if="conversation" class="flex min-h-[560px] flex-col gap-4 rounded-[24px] border border-line p-4">
            <header class="flex flex-wrap items-baseline justify-between gap-2">
                <h1 class="text-[16px]">Диалог №{{ conversation.id }}</h1>
                <span v-if="conversation.status === 'ai'" class="rounded-[46px] bg-accent px-3 py-1 text-[14px]">Отвечает ИИ</span>
                <span v-else class="rounded-[46px] bg-chip px-3 py-1 text-[14px]">
                    У менеджера<template v-if="conversation.handoff_reason">: {{ conversation.handoff_reason }}</template>
                </span>
            </header>

            <div ref="thread" class="flex max-h-[60vh] flex-1 flex-col gap-4 overflow-y-auto">
                <p v-if="messages.length === 0" class="text-[14px] text-muted">
                    Напишите как покупатель. ИИ-менеджер подберёт квартиры, посчитает ипотеку, ответит по базе знаний или позовёт менеджера.
                </p>
                <article
                    v-for="message in messages"
                    :key="message.id"
                    class="flex max-w-[85%] flex-col gap-1"
                    :class="message.role === 'buyer' ? 'self-end items-end' : 'self-start'"
                >
                    <span class="text-[12px] text-muted">{{ roles[message.role] }} · {{ time.format(new Date(message.created_at)) }}</span>
                    <p
                        class="rounded-[24px] px-4 py-2 text-[14px] whitespace-pre-line"
                        :class="message.role === 'buyer' ? 'bg-capsule' : 'border border-line'"
                    >
                        {{ message.text }}
                    </p>
                    <AgentTurn v-if="message.run" :run="message.run" />
                    <template v-if="message.saved_search">
                        <Button
                            v-if="message.saved_search.active"
                            label="Отписаться"
                            class="self-start"
                            severity="secondary"
                            variant="outlined"
                            size="small"
                            @click="unsubscribe(message.saved_search.id)"
                        />
                        <span v-else class="text-[12px] text-muted">Подписка отключена</span>
                    </template>
                </article>
                <p v-if="waiting" class="self-start text-[14px] text-muted motion-safe:animate-pulse">ИИ-менеджер отвечает…</p>
            </div>

            <div v-if="conversation.status === 'ai' && messages.length === 0" class="flex flex-wrap gap-2">
                <button
                    v-for="example in examples"
                    :key="example"
                    type="button"
                    class="rounded-[46px] border border-line px-3 py-1 text-[14px] text-muted transition-colors duration-150 hover:text-ink"
                    @click="send(example)"
                >
                    {{ example }}
                </button>
            </div>

            <form class="flex flex-col gap-1" @submit.prevent="send()">
                <div class="flex gap-2">
                    <InputText
                        v-model="form.text"
                        class="min-w-0 flex-1"
                        placeholder="Сообщение покупателя"
                        aria-label="Сообщение покупателя"
                        maxlength="4000"
                    />
                    <Button type="submit" label="Отправить" :loading="form.processing" />
                </div>
                <span v-if="form.errors.text" class="text-[14px] text-danger">{{ form.errors.text }}</span>
                <span v-else-if="conversation.status !== 'ai'" class="text-[14px] text-muted">
                    Диалог у менеджера: ИИ больше не отвечает. Начните новый диалог, чтобы проверить агента снова.
                </span>
            </form>
        </section>

        <section v-else class="flex flex-col items-start gap-3 rounded-[24px] border border-line p-4">
            <h1 class="text-[25px]">Тестовый чат</h1>
            <p class="text-[14px] text-muted">
                Пишите как покупатель и смотрите, как отвечает ИИ-менеджер: какие инструменты он вызвал, прошёл ли ответ проверку, когда
                передал диалог менеджеру.
            </p>
            <Button label="Начать диалог" @click="startConversation" />
        </section>
    </div>
</template>
