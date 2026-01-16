import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Make Pusher available globally for Echo
declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

// Suppress Pusher connection errors in console (Reverb may not be running)
Pusher.logToConsole = false;
window.Pusher = Pusher;

let echoInstance: Echo<'reverb'> | null = null;
let currentTld: string | null = null;
let connectionFailed = false;

export interface Environment {
    id: number;
    tld: string;
    [key: string]: any;
}

export function useEcho() {
    const connect = (environment: Environment) => {
        // Skip if already connected to this environment
        if (echoInstance && currentTld === environment.tld) {
            return echoInstance;
        }

        // Disconnect from previous environment
        disconnect();

        const reverbHost = `orbit.${environment.tld}`;

        try {
            echoInstance = new Echo({
                broadcaster: 'reverb',
                key: 'orbit-key',
                wsHost: reverbHost,
                wsPort: 8080,
                wssPort: 8080,
                forceTLS: false,
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
            });

            // Listen for connection state changes
            echoInstance.connector.pusher.connection.bind('connected', () => {
                connectionFailed = false;
                console.log('[Echo] Connected to Reverb');
            });

            echoInstance.connector.pusher.connection.bind('failed', () => {
                connectionFailed = true;
                // Silently fail - Reverb may not be running
                console.debug('[Echo] Reverb connection unavailable - real-time updates disabled');
            });

            echoInstance.connector.pusher.connection.bind('unavailable', () => {
                connectionFailed = true;
                console.debug('[Echo] Reverb unavailable - real-time updates disabled');
            });

            currentTld = environment.tld;

            return echoInstance;
        } catch (error) {
            console.debug('[Echo] Failed to initialize:', error);
            connectionFailed = true;
            return null;
        }
    };

    const disconnect = () => {
        if (echoInstance) {
            try {
                echoInstance.disconnect();
            } catch {
                // Ignore disconnect errors
            }
            echoInstance = null;
            currentTld = null;
            connectionFailed = false;
        }
    };

    const getEcho = () => echoInstance;

    const isConnected = () => echoInstance !== null && !connectionFailed;

    return {
        connect,
        disconnect,
        getEcho,
        isConnected,
    };
}
