<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import type { WidgetMessage } from './types';
import { useWidget } from './useWidget';

const props = defineProps<{ origin: string; channelKey: string }>();

const widget = useWidget(props.origin, props.channelKey);
const { channel, dialog, isOpen, sending, error, phoneError, unread } = widget;

const draft = ref('');
const phone = ref('');
const list = ref<HTMLElement | null>(null);

const roles: Record<WidgetMessage['role'], string> = { buyer: 'Вы', agent: 'ИИ-помощник', manager: 'Менеджер' };

const subtitle = computed(() =>
    dialog.value?.status === 'human' ? 'Диалог у менеджера отдела продаж' : 'ИИ-помощник · отвечает круглосуточно',
);
const canWrite = computed(() => dialog.value?.consented === true && !sending.value);

/**
 * The model marks bold with «**»; the text is shown as text, the marked parts in bold.
 */
function parts(text: string): { text: string; bold: boolean }[] {
    return text.split('**').map((part, index) => ({ text: part, bold: index % 2 === 1 }));
}

async function send(): Promise<void> {
    const text = draft.value.trim();

    if (text === '' || !canWrite.value) {
        return;
    }

    if (await widget.send(text)) {
        draft.value = '';
    }
}

async function sharePhone(): Promise<void> {
    if (phone.value.trim() !== '' && (await widget.sharePhone(phone.value.trim()))) {
        phone.value = '';
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        void send();
    }
}

watch(
    () => [dialog.value?.messages.length, dialog.value?.waiting, isOpen.value],
    async () => {
        await nextTick();
        list.value?.scrollTo({ top: list.value.scrollHeight });
    },
);

onMounted(() => void widget.start());
</script>

<template>
    <div v-if="channel" class="widget">
        <section v-if="isOpen" class="panel" role="dialog" aria-label="Чат с отделом продаж">
            <header class="header">
                <div>
                    <p class="title">{{ channel.name }}</p>
                    <p class="subtitle">{{ subtitle }}</p>
                </div>
                <button class="icon-button" type="button" aria-label="Свернуть чат" @click="widget.close()">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
            </header>

            <div ref="list" class="messages">
                <div v-if="dialog && !dialog.consented" class="consent">
                    <p>{{ channel.introduction }}</p>
                    <p>
                        Чтобы продолжить, подтвердите согласие на
                        <a :href="channel.policy_url" target="_blank" rel="noopener">обработку персональных данных</a>.
                    </p>
                    <button class="primary" type="button" :disabled="sending" @click="widget.consent()">Согласен</button>
                </div>
                <p v-else-if="dialog && dialog.messages.length === 0" class="hint">
                    Напишите, какую квартиру ищете, или задайте вопрос о ЖК и ипотеке.
                </p>
                <p v-else-if="!dialog" class="hint">Загружаем чат…</p>

                <article v-for="message in dialog?.messages ?? []" :key="message.id" class="message" :class="message.role">
                    <p v-if="message.role === 'manager'" class="author">{{ roles[message.role] }}</p>
                    <div class="bubble">
                        <template v-for="(part, index) in parts(message.text)" :key="index">
                            <strong v-if="part.bold">{{ part.text }}</strong>
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </div>

                    <div v-if="message.flats.length > 0" class="cards">
                        <div v-for="flat in message.flats" :key="flat.id" class="card">
                            <img v-if="flat.plan_url" :src="flat.plan_url" :alt="`Планировка: ${flat.title}`" loading="lazy" />
                            <div class="card-body">
                                <p class="card-title">{{ flat.title }}</p>
                                <p class="card-place">{{ flat.place }}</p>
                                <p class="card-details">{{ flat.details }}</p>
                                <p v-if="flat.reserved" class="badge">Забронирована</p>
                                <div class="card-actions">
                                    <button class="primary" type="button" :disabled="!canWrite" @click="widget.askAboutFlat(flat.id, 'booking')">
                                        Записаться
                                    </button>
                                    <button class="secondary" type="button" :disabled="!canWrite" @click="widget.askAboutFlat(flat.id, 'similar')">
                                        Похожие
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="message.saved_search" class="subscription">
                        <button v-if="message.saved_search.active" class="secondary" type="button" :disabled="sending" @click="widget.unsubscribe(message.saved_search.id)">
                            Отписаться
                        </button>
                        <span v-else>Подписка отключена</span>
                    </div>
                </article>

                <p v-if="dialog?.waiting" class="typing" aria-live="polite"><span /><span /><span /> ИИ-помощник отвечает…</p>

                <form v-if="dialog?.phone_requested" class="phone" @submit.prevent="sharePhone">
                    <p>Чтобы менеджер мог с вами связаться, оставьте, пожалуйста, телефон.</p>
                    <div class="phone-row">
                        <input v-model="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+7 916 123-45-67" aria-label="Телефон" />
                        <button class="primary" type="submit" :disabled="sending">Отправить</button>
                    </div>
                    <p v-if="phoneError" class="error">{{ phoneError }}</p>
                </form>
            </div>

            <p v-if="error" class="error composer-error" role="alert">{{ error }}</p>
            <form class="composer" @submit.prevent="send">
                <textarea
                    v-model="draft"
                    rows="1"
                    maxlength="4000"
                    :disabled="!dialog?.consented"
                    :placeholder="dialog?.consented ? 'Напишите сообщение…' : 'Сначала подтвердите согласие'"
                    aria-label="Сообщение"
                    @keydown="onKeydown"
                />
                <button class="send" type="submit" :disabled="!canWrite || draft.trim() === ''" aria-label="Отправить">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l16-8-6 16-2-6-8-2z" /></svg>
                </button>
            </form>
        </section>

        <button v-else class="launcher" type="button" aria-label="Открыть чат с отделом продаж" @click="widget.open()">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4V5z" /></svg>
            <span v-if="unread > 0" class="unread">{{ unread }}</span>
        </button>
    </div>
</template>

<style>
:host {
    /* The night theme of the cabinet (app.css): the widget cannot read the site's styles, nor the site its own. */
    --hf-accent: #7c4dff;
    --hf-on-accent: #ffffff;
    --hf-lavender: #d9c8ff;
    --hf-ink: #f4f2fb;
    --hf-muted: #a7a3b8;
    --hf-line: #2c2840;
    --hf-soft: #2a2640;
    --hf-surface: #1b1828;
    --hf-page: #12101c;
    --hf-danger: #de675a;

    all: initial;
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 2147483000;
    color: var(--hf-ink);
    color-scheme: dark;
    font: 15px/1.45 -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
}

* {
    box-sizing: border-box;
}

p {
    margin: 0;
}

button {
    font: inherit;
    cursor: pointer;
}

button:disabled {
    cursor: default;
    opacity: 0.5;
}

button:focus-visible,
textarea:focus-visible,
input:focus-visible,
a:focus-visible {
    outline: 2px solid var(--hf-lavender);
    outline-offset: 2px;
}

svg {
    width: 22px;
    height: 22px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.launcher {
    position: relative;
    display: grid;
    place-items: center;
    width: 60px;
    height: 60px;
    border: 0;
    border-radius: 50%;
    background: var(--hf-accent);
    color: var(--hf-on-accent);
    box-shadow: 0 8px 24px rgb(0 0 0 / 0.4);
}

.launcher svg {
    width: 28px;
    height: 28px;
}

.unread {
    position: absolute;
    top: -2px;
    right: -2px;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    border-radius: 11px;
    background: var(--hf-lavender);
    color: var(--hf-page);
    font-size: 12px;
    font-weight: 700;
    line-height: 22px;
}

.panel {
    display: flex;
    flex-direction: column;
    width: 380px;
    height: min(640px, calc(100vh - 40px));
    overflow: hidden;
    border: 1px solid var(--hf-line);
    border-radius: 24px;
    background: var(--hf-surface);
    box-shadow: 0 16px 48px rgb(0 0 0 / 0.5);
}

.header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 16px 16px 20px;
    border-bottom: 1px solid var(--hf-line);
    background: var(--hf-page);
    color: var(--hf-ink);
}

.title {
    font-weight: 700;
}

.subtitle {
    color: var(--hf-muted);
    font-size: 13px;
}

.icon-button {
    display: grid;
    place-items: center;
    width: 36px;
    height: 36px;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: var(--hf-ink);
}

.messages {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 12px;
    padding: 16px;
    overflow-y: auto;
}

.hint {
    margin: auto 0;
    color: var(--hf-muted);
    text-align: center;
}

.consent,
.phone {
    display: grid;
    gap: 10px;
    padding: 16px;
    border-radius: 16px;
    background: var(--hf-soft);
}

.consent a {
    color: inherit;
}

.consent .primary {
    justify-self: start;
}

.message {
    display: grid;
    gap: 6px;
    max-width: 88%;
}

.message.buyer {
    align-self: flex-end;
}

.author {
    color: var(--hf-muted);
    font-size: 12px;
}

.bubble {
    padding: 10px 14px;
    border-radius: 16px 16px 16px 4px;
    background: var(--hf-soft);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.buyer .bubble {
    border-radius: 16px 16px 4px 16px;
    background: var(--hf-accent);
    color: var(--hf-on-accent);
}

.manager .bubble {
    border: 1px solid var(--hf-lavender);
    background: var(--hf-surface);
}

.cards {
    display: grid;
    gap: 8px;
}

.card {
    overflow: hidden;
    border: 1px solid var(--hf-line);
    border-radius: 16px;
}

.card img {
    display: block;
    width: 100%;
    height: 160px;
    padding: 8px;
    object-fit: contain;
    /* Plans are drawn dark on light, as the feed gives them. */
    background: var(--hf-ink);
    border-bottom: 1px solid var(--hf-line);
}

.card-body {
    display: grid;
    gap: 2px;
    padding: 12px;
}

.card-title {
    font-weight: 700;
}

.card-place,
.card-details {
    color: var(--hf-muted);
    font-size: 13px;
}

.badge {
    justify-self: start;
    margin-top: 4px;
    padding: 2px 8px;
    border-radius: 10px;
    background: var(--hf-soft);
    font-size: 12px;
}

.card-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.primary,
.secondary {
    padding: 8px 16px;
    border-radius: 46px;
    font-weight: 500;
}

.primary {
    border: 1px solid var(--hf-accent);
    background: var(--hf-accent);
    color: var(--hf-on-accent);
}

.secondary {
    border: 1px solid var(--hf-line);
    background: transparent;
    color: var(--hf-ink);
}

.subscription span {
    color: var(--hf-muted);
    font-size: 13px;
}

.typing {
    display: flex;
    align-items: center;
    gap: 4px;
    color: var(--hf-muted);
    font-size: 13px;
}

.typing span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--hf-muted);
    animation: blink 1.2s infinite;
}

.typing span:nth-child(2) {
    animation-delay: 0.2s;
}

.typing span:nth-child(3) {
    margin-right: 4px;
    animation-delay: 0.4s;
}

@keyframes blink {
    0%,
    80%,
    100% {
        opacity: 0.25;
    }

    40% {
        opacity: 1;
    }
}

.phone-row {
    display: flex;
    gap: 8px;
}

.phone input {
    flex: 1;
    min-width: 0;
    padding: 8px 14px;
    border: 1px solid var(--hf-line);
    border-radius: 46px;
    background: var(--hf-page);
    color: var(--hf-ink);
    font: inherit;
}

input::placeholder,
textarea::placeholder {
    color: var(--hf-muted);
}

.error {
    color: var(--hf-danger);
    font-size: 13px;
}

.composer-error {
    padding: 0 16px 8px;
}

.composer {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding: 12px;
    border-top: 1px solid var(--hf-line);
}

.composer textarea {
    flex: 1;
    max-height: 120px;
    padding: 10px 16px;
    border: 1px solid var(--hf-line);
    border-radius: 22px;
    background: var(--hf-page);
    color: var(--hf-ink);
    font: inherit;
    resize: none;
}

.send {
    display: grid;
    place-items: center;
    flex: none;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 50%;
    background: var(--hf-accent);
    color: var(--hf-on-accent);
}

@media (max-width: 480px) {
    :host {
        right: 12px;
        bottom: 12px;
    }

    .panel {
        position: fixed;
        inset: 0;
        width: auto;
        height: auto;
        border: 0;
        border-radius: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .typing span {
        animation: none;
    }
}
</style>
