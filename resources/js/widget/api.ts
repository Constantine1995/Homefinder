import type { FlatRequest, WidgetChannel, WidgetDialog } from './types';

/**
 * A refused request, with the text the visitor should read: Laravel's `message`, or the first validation error.
 */
export class WidgetApiError extends Error {
    constructor(
        public status: number,
        message: string,
    ) {
        super(message);
    }
}

const FALLBACK_ERROR = 'Не получилось отправить — проверьте интернет и попробуйте ещё раз.';

/**
 * The widget's API on the app that served the script (routes/widget.php).
 *
 * The visitor's token is started on the first request that needs it, not on page load, so a page view does not make
 * a buyer; the browser keeps it, and a token the app no longer knows is replaced by a new one.
 */
export class WidgetApi {
    private readonly base: string;

    private readonly storageKey: string;

    private token: string | null;

    private startingVisitor: Promise<string> | null = null;

    constructor(
        origin: string,
        private readonly key: string,
    ) {
        this.base = `${origin}/api/v1/widget`;
        this.storageKey = `homefinder-widget:${key}`;
        this.token = this.readToken();
    }

    /**
     * The visitor's token for the private channel's authorization, once the visitor has one.
     */
    get visitorToken(): string | null {
        return this.token;
    }

    get broadcastingAuthUrl(): string {
        return `${this.base}/broadcasting/auth`;
    }

    channel(): Promise<WidgetChannel> {
        return this.request<WidgetChannel>('GET', `/channels/${encodeURIComponent(this.key)}`, undefined, false);
    }

    dialog(): Promise<WidgetDialog> {
        return this.request<WidgetDialog>('GET', '/conversation');
    }

    consent(): Promise<WidgetDialog> {
        return this.request<WidgetDialog>('POST', '/consent');
    }

    send(text: string): Promise<WidgetDialog> {
        return this.request<WidgetDialog>('POST', '/messages', { text });
    }

    askAboutFlat(flatId: number, request: FlatRequest): Promise<WidgetDialog> {
        return this.request<WidgetDialog>('POST', `/flats/${flatId}/${request}`);
    }

    sharePhone(phone: string): Promise<WidgetDialog> {
        return this.request<WidgetDialog>('POST', '/phone', { phone });
    }

    unsubscribe(savedSearchId: number): Promise<WidgetDialog> {
        return this.request<WidgetDialog>('DELETE', `/saved-searches/${savedSearchId}`);
    }

    private async request<T>(method: string, path: string, body?: object, asVisitor = true, retried = false): Promise<T> {
        const headers: Record<string, string> = { Accept: 'application/json' };

        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }

        if (asVisitor) {
            headers.Authorization = `Bearer ${await this.ensureToken()}`;
        }

        let response: Response;

        try {
            response = await fetch(this.base + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
        } catch {
            throw new WidgetApiError(0, FALLBACK_ERROR);
        }

        if (response.status === 401 && asVisitor && !retried) {
            this.forgetToken();

            return this.request<T>(method, path, body, asVisitor, true);
        }

        const data = await response.json().catch(() => null);

        if (!response.ok) {
            throw new WidgetApiError(response.status, errorText(data));
        }

        return data as T;
    }

    private ensureToken(): Promise<string> {
        if (this.token !== null) {
            return Promise.resolve(this.token);
        }

        // Requests sent together share one new visitor.
        this.startingVisitor ??= this.startVisitor().finally(() => {
            this.startingVisitor = null;
        });

        return this.startingVisitor;
    }

    private async startVisitor(): Promise<string> {
        const { token } = await this.request<{ token: string }>('POST', `/channels/${encodeURIComponent(this.key)}/visitors`, undefined, false);
        this.token = token;

        try {
            localStorage.setItem(this.storageKey, token);
        } catch {
            // Without storage the visitor stays for this page only.
        }

        return token;
    }

    private readToken(): string | null {
        try {
            return localStorage.getItem(this.storageKey);
        } catch {
            return null;
        }
    }

    private forgetToken(): void {
        this.token = null;

        try {
            localStorage.removeItem(this.storageKey);
        } catch {
            // Nothing was stored.
        }
    }
}

function errorText(data: unknown): string {
    if (typeof data !== 'object' || data === null) {
        return FALLBACK_ERROR;
    }

    const { message, errors } = data as { message?: unknown; errors?: Record<string, unknown> };
    const firstError = errors === undefined ? undefined : Object.values(errors).flat()[0];

    if (typeof firstError === 'string') {
        return firstError;
    }

    return typeof message === 'string' && message !== '' && !message.startsWith('Server Error') ? message : FALLBACK_ERROR;
}
