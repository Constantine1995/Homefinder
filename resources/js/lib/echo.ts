import { configureEcho } from '@laravel/echo-vue';

interface ChannelAuthorizationRequest {
    socketId: string;
    channelName: string;
}

type ChannelAuthorizationCallback = (error: Error | null, authData: { auth: string } | null) => void;

/**
 * Laravel's CSRF token as the XSRF-TOKEN cookie holds it now.
 */
function xsrfToken(): string {
    const cookie = document.cookie.split('; ').find((pair) => pair.startsWith('XSRF-TOKEN='));

    return cookie === undefined ? '' : decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

/**
 * Connect to Reverb, through nginx on the app's own port (VITE_REVERB_*).
 *
 * Private channels are authorized with the token from the cookie, read on every subscription: the session's token
 * changes at login, which Inertia does without loading the page, so a token read once at startup would be refused.
 */
export function connectEcho(): void {
    configureEcho({
        broadcaster: 'reverb',
        channelAuthorization: {
            customHandler: ({ socketId, channelName }: ChannelAuthorizationRequest, callback: ChannelAuthorizationCallback) => {
                fetch('/broadcasting/auth', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                    body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
                })
                    .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
                    .then((authData) => callback(null, authData))
                    .catch((error: Error) => callback(error, null));
            },
        },
    });
}
