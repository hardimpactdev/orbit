import '../css/app.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import type { Page } from '@inertiajs/core';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import Layout from '@/layouts/Layout.vue';
import { createPinia } from 'pinia';
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate';
import { configureEcho } from '@laravel/echo-vue';
import Pusher from 'pusher-js';
import { globalProvisioningHandler } from '@/composables/useProjectProvisioning';

window.Pusher = Pusher;

declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

interface ReverbConfig {
    enabled: boolean;
    host: string;
    port: number;
    scheme: 'http' | 'https';
    app_key: string;
}

interface PageProps extends Record<string, unknown> {
    reverb?: ReverbConfig | null;
}

// Track if Echo has been configured to avoid reconfiguring on Inertia navigation
let echoConfigured = false;

const configureReverbEcho = (page: Page<PageProps>) => {
    // Only configure Echo once - reconfiguring on every navigation
    // resets the Pusher connection and loses channel subscriptions
    if (echoConfigured) {
        return;
    }

    const reverb = page.props.reverb;

    if (reverb?.enabled) {
        configureEcho({
            broadcaster: 'reverb',
            key: reverb.app_key,
            wsHost: reverb.host,
            wsPort: reverb.port,
            wssPort: reverb.port,
            forceTLS: reverb.scheme === 'https',
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            Pusher: window.Pusher,
        });
        echoConfigured = true;

        // Set up global provisioning channel listener that persists across navigations
        // This bypasses useEchoPublic's lifecycle hooks that cause missed events
        setTimeout(() => {
            const pusherInstance = window.Pusher.instances?.[0];
            if (pusherInstance) {
                const channel = pusherInstance.subscribe('provisioning');
                channel.bind('project.provision.status', globalProvisioningHandler);
                channel.bind('project.deletion.status', globalProvisioningHandler);
            }
        }, 100);

        return;
    }

    configureEcho({
        broadcaster: 'null',
    });
    echoConfigured = true;
};

const pinia = createPinia();
pinia.use(piniaPluginPersistedstate);

createInertiaApp({
    title: (title) => (title ? `${title} - Orbit` : 'Orbit'),
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./pages/**/*.vue'),
        );
        page.default.layout = page.default.layout || Layout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        const page = props.initialPage as Page<PageProps>;
        configureReverbEcho(page);

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .mount(el);
    },
    progress: {
        color: '#3B82F6',
    },
});
