<script setup lang="ts">
import { computed } from 'vue';
import type { AgentRun } from '@/types/chat';

const props = defineProps<{ run: AgentRun }>();

const intents: Record<string, string> = {
    search: 'подбор',
    details: 'о квартире',
    compare: 'сравнение',
    mortgage: 'ипотека',
    knowledge: 'вопрос о ЖК',
    booking: 'запись',
    handoff: 'нужен менеджер',
    smalltalk: 'разговор',
};

const seconds = computed(() => (props.run.duration_ms / 1000).toLocaleString('ru-RU', { maximumFractionDigits: 1 }));
const gaps = computed(() => props.run.actions.filter((action) => action.type === 'knowledge_gap'));
</script>

<template>
    <details class="text-[14px] text-muted">
        <summary class="cursor-pointer select-none transition-colors duration-150 hover:text-ink">
            <template v-if="run.error">агент не ответил</template>
            <template v-else>
                {{ intents[run.intent ?? ''] ?? run.intent }} ·
                <span :class="run.verified ? 'text-success' : 'text-danger'">{{ run.verified ? 'проверено' : 'не прошло проверку' }}</span>
                · {{ seconds }} с · {{ run.prompt_tokens + run.completion_tokens }} токенов
            </template>
            <template v-if="gaps.length"> · <span class="text-danger">нет ответа в базе знаний</span></template>
        </summary>
        <div class="mt-2 flex flex-col gap-2 rounded-[24px] border border-line p-4">
            <p v-if="run.error" class="text-danger">{{ run.error }}</p>
            <p v-if="run.steps.length === 0 && !run.error">Инструменты не вызывались.</p>
            <div v-for="(step, index) in run.steps" :key="index" class="flex flex-col gap-1">
                <code class="text-ink">{{ step.tool }}({{ JSON.stringify(step.args) }})</code>
                <pre class="whitespace-pre-wrap font-[inherit]">{{ step.summary }}</pre>
            </div>
            <p v-if="run.attempts > 1">Ответ переписан после проверки: {{ run.attempts }} попытки.</p>
            <p v-if="run.problems.length" class="text-danger">Проверка: {{ run.problems.join('; ') }}</p>
            <p v-for="gap in gaps" :key="gap.reason">Задача менеджеру: ответить на «{{ gap.reason }}» и пополнить базу знаний.</p>
        </div>
    </details>
</template>
