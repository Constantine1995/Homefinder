import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

interface ChannelAuthorizationRequest {
    socketId: string;
    channelName: string;
}

type ChannelAuthorizationCallback = (error: Error | null, authData: { auth: string } | null) => void;

/**
 * Follow the visitor's private channel on Reverb (App\Events\WidgetMessageSent) and call back on each message of the
 * sales side; returns a function that leaves it.
 *
 * The channel is authorized with the visitor's token, not a session: the widget lives on another site, without the
 * app's cookies. Reverb is reached on the host and port the app was built with (VITE_REVERB_*).
 */
export function followVisitorChannel(channel: string, authUrl: string, token: () => string | null, onMessage: () => void): () => void {
    const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80));

    const echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            customHandler: ({ socketId, channelName }: ChannelAuthorizationRequest, callback: ChannelAuthorizationCallback) => {
                fetch(authUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${token() ?? ''}` },
                    body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
                })
                    .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
                    .then((authData) => callback(null, authData))
                    .catch((error: Error) => callback(error, null));
            },
        },
    });

    echo.private(channel).listen('.message.sent', onMessage);

    return () => echo.disconnect();
}
