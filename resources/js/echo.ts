import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Make Pusher available globally for Echo
(window as any).Pusher = Pusher;

let echoInstance: Echo<'reverb'> | null = null;

export interface ReverbConfig {
    host: string;
    port: number;
    scheme: 'http' | 'https';
    key: string;
}

/**
 * Get or create an Echo instance for connecting to Orbit's standalone Reverb server.
 */
export function getEcho(config: ReverbConfig): Echo<'reverb'> {
    echoInstance = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
    });

    return echoInstance;
}

/**
 * Disconnect and cleanup Echo instance.
 */
export function disconnectEcho(): void {
    if (echoInstance) {
        echoInstance.disconnect();
        echoInstance = null;
    }
}
