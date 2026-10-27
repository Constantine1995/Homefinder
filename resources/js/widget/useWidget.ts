import { onBeforeUnmount, ref, shallowRef } from 'vue';
import { WidgetApi, WidgetApiError } from './api';
import { followVisitorChannel } from './realtime';
import type { FlatRequest, WidgetChannel, WidgetDialog } from './types';

/**
 * How often the widget asks for the agent's reply while it waits: the websocket usually brings it sooner, this is for
 * a site where Reverb cannot be reached.
 */
const WAITING_POLL_MS = 3000;

/**
 * The state and actions of the site widget: the channel's introduction, the visitor's dialog, and what is being sent.
 */
export function useWidget(origin: string, key: string) {
    const api = new WidgetApi(origin, key);
    const channel = shallowRef<WidgetChannel | null>(null);
    const dialog = shallowRef<WidgetDialog | null>(null);
    const isOpen = ref(false);
    const sending = ref(false);
    const error = ref<string | null>(null);
    const phoneError = ref<string | null>(null);
    const unread = ref(0);

    let pollTimer: ReturnType<typeof setTimeout> | undefined;
    let leaveChannel: (() => void) | undefined;

    /**
     * Load the introduction; a returning visitor's dialog is loaded too, so news that came meanwhile shows as unread.
     */
    async function start(): Promise<void> {
        try {
            channel.value = await api.channel();
        } catch {
            channel.value = null;

            return;
        }

        if (api.visitorToken !== null) {
            await refresh();
        }
    }

    async function open(): Promise<void> {
        isOpen.value = true;
        unread.value = 0;

        if (dialog.value === null) {
            await refresh();
        }
    }

    function close(): void {
        isOpen.value = false;
    }

    async function refresh(): Promise<void> {
        try {
            apply(await api.dialog());
        } catch (exception) {
            error.value = exception instanceof WidgetApiError ? exception.message : null;
        }
    }

    function apply(next: WidgetDialog): void {
        const knownIds = new Set(dialog.value?.messages.map((message) => message.id) ?? []);
        const arrived = next.messages.filter((message) => message.role !== 'buyer' && !knownIds.has(message.id)).length;

        if (!isOpen.value && dialog.value !== null) {
            unread.value += arrived;
        }

        dialog.value = next;
        clearTimeout(pollTimer);

        if (next.waiting) {
            pollTimer = setTimeout(refresh, WAITING_POLL_MS);
        }

        leaveChannel ??= followVisitorChannel(next.channel, api.broadcastingAuthUrl, () => api.visitorToken, refresh);
    }

    async function run(action: () => Promise<WidgetDialog>, onError: (message: string) => void = (message) => (error.value = message)): Promise<boolean> {
        sending.value = true;
        error.value = null;

        try {
            apply(await action());

            return true;
        } catch (exception) {
            onError(exception instanceof WidgetApiError ? exception.message : String(exception));

            return false;
        } finally {
            sending.value = false;
        }
    }

    onBeforeUnmount(() => {
        clearTimeout(pollTimer);
        leaveChannel?.();
    });

    return {
        channel,
        dialog,
        isOpen,
        sending,
        error,
        phoneError,
        unread,
        start,
        open,
        close,
        consent: () => run(() => api.consent()),
        send: (text: string) => run(() => api.send(text)),
        askAboutFlat: (flatId: number, request: FlatRequest) => run(() => api.askAboutFlat(flatId, request)),
        unsubscribe: (savedSearchId: number) => run(() => api.unsubscribe(savedSearchId)),
        sharePhone: (phone: string) => {
            phoneError.value = null;

            return run(() => api.sharePhone(phone), (message) => (phoneError.value = message));
        },
    };
}
