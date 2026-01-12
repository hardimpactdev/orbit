<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import {
    Loader2, Play, Square, RefreshCw, Server, Database, Mail, Globe,
    Wifi, Container, FileText, X, ChevronDown, ChevronUp
} from 'lucide-vue-next';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
}

interface Service {
    status: string;
    container: string;
}

interface ServiceMeta {
    name: string;
    description: string;
    icon: typeof Server;
    ports?: string;
    category: 'core' | 'database' | 'php' | 'utility';
}

const props = defineProps<{
    environment: Environment;
    remoteApiUrl: string | null;
}>();

// Helper to get the API URL - uses remote API directly when available
const getApiUrl = (path: string) => {
    if (props.remoteApiUrl) {
        return `${props.remoteApiUrl}${path}`;
    }
    return `/api/environments/${props.environment.id}${path}`;
};

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

const services = ref<Record<string, Service>>({});
const loading = ref(true);
const servicesRunning = ref(0);
const servicesTotal = ref(0);
const restartingAll = ref(false);
const actionInProgress = ref<string | null>(null);

// Logs
const showLogs = ref(false);
const logsService = ref<string | null>(null);
const logs = ref<string>('');
const logsLoading = ref(false);
const logsAutoRefresh = ref(false);
let logsInterval: ReturnType<typeof setInterval> | null = null;

// Service metadata
const serviceMeta: Record<string, ServiceMeta> = {
    'dns': {
        name: 'DNS Server',
        description: 'Resolves local domains to Launchpad',
        icon: Globe,
        ports: '53',
        category: 'core',
    },
    'caddy': {
        name: 'Caddy Web Server',
        description: 'HTTPS reverse proxy with automatic certificates',
        icon: Server,
        ports: '80, 443',
        category: 'core',
    },
    'php-83': {
        name: 'PHP 8.3',
        description: 'FrankenPHP application server',
        icon: Container,
        ports: '8083',
        category: 'php',
    },
    'php-84': {
        name: 'PHP 8.4',
        description: 'FrankenPHP application server',
        icon: Container,
        ports: '8084',
        category: 'php',
    },
    'php-85': {
        name: 'PHP 8.5',
        description: 'FrankenPHP application server',
        icon: Container,
        ports: '8085',
        category: 'php',
    },
    'postgres': {
        name: 'PostgreSQL',
        description: 'Relational database server',
        icon: Database,
        ports: '5432',
        category: 'database',
    },
    'redis': {
        name: 'Redis',
        description: 'In-memory cache and message broker',
        icon: Database,
        ports: '6379',
        category: 'database',
    },
    'mailpit': {
        name: 'Mailpit',
        description: 'Email testing and capture',
        icon: Mail,
        ports: '1025, 8025',
        category: 'utility',
    },
    'reverb': {
        name: 'Laravel Reverb',
        description: 'WebSocket server for real-time features',
        icon: Wifi,
        ports: '6001',
        category: 'utility',
    },
};

const categories = [
    { key: 'core', label: 'Core Services' },
    { key: 'php', label: 'PHP Servers' },
    { key: 'database', label: 'Databases' },
    { key: 'utility', label: 'Utilities' },
];

const servicesByCategory = computed(() => {
    const result: Record<string, Array<{ key: string; service: Service; meta: ServiceMeta }>> = {
        core: [],
        php: [],
        database: [],
        utility: [],
    };

    for (const [key, service] of Object.entries(services.value)) {
        const meta = serviceMeta[key] || {
            name: key,
            description: 'Service',
            icon: Container,
            category: 'utility',
        };
        result[meta.category].push({ key, service, meta });
    }

    // Sort PHP services by version
    result.php.sort((a, b) => a.key.localeCompare(b.key));

    return result;
});

const allRunning = computed(() => servicesRunning.value === servicesTotal.value && servicesTotal.value > 0);
const allStopped = computed(() => servicesRunning.value === 0);

async function loadStatus(silent = false) {
    if (!silent) {
        loading.value = true;
    }
    try {
        const response = await fetch(getApiUrl('/status'));
        const result = await response.json();

        if (result.success && result.data) {
            services.value = result.data.services || {};
            servicesRunning.value = result.data.services_running || 0;
            servicesTotal.value = result.data.services_total || 0;
        }
    } catch (error) {
        console.error('Failed to load status:', error);
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
}

async function startAll() {
    actionInProgress.value = 'start-all';
    try {
        const response = await fetch(getApiUrl('/start'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        });
        const result = await response.json();

        if (result.success) {
            await loadStatus(true);
        } else {
            alert('Failed to start services: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to start services');
    } finally {
        actionInProgress.value = null;
    }
}

async function stopAll() {
    actionInProgress.value = 'stop-all';
    try {
        const response = await fetch(getApiUrl('/stop'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        });
        const result = await response.json();

        if (result.success) {
            await loadStatus(true);
        } else {
            alert('Failed to stop services: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to stop services');
    } finally {
        actionInProgress.value = null;
    }
}

async function restartAll() {
    restartingAll.value = true;
    actionInProgress.value = 'restart-all';
    try {
        const response = await fetch(getApiUrl('/restart'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        });
        const result = await response.json();

        if (result.success) {
            await loadStatus(true);
        } else {
            alert('Failed to restart services: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to restart services');
    } finally {
        restartingAll.value = false;
        actionInProgress.value = null;
    }
}

async function serviceAction(serviceKey: string, action: 'start' | 'stop' | 'restart') {
    actionInProgress.value = `${action}-${serviceKey}`;
    try {
        const response = await fetch(getApiUrl(`/services/${serviceKey}/${action}`), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        });
        const result = await response.json();

        if (result.success) {
            await loadStatus(true);
        } else {
            alert(`Failed to ${action} service: ` + (result.error || 'Unknown error'));
        }
    } catch {
        alert(`Failed to ${action} service`);
    } finally {
        actionInProgress.value = null;
    }
}

async function openLogs(serviceKey: string) {
    logsService.value = serviceKey;
    showLogs.value = true;
    await fetchLogs();
}

async function fetchLogs() {
    if (!logsService.value) return;

    logsLoading.value = true;
    try {
        const response = await fetch(getApiUrl(`/services/${logsService.value}/logs`));
        const result = await response.json();

        if (result.success) {
            logs.value = result.logs || 'No logs available';
        } else {
            logs.value = 'Failed to fetch logs: ' + (result.error || 'Unknown error');
        }
    } catch {
        logs.value = 'Failed to fetch logs';
    } finally {
        logsLoading.value = false;
    }
}

function closeLogs() {
    showLogs.value = false;
    logsService.value = null;
    logs.value = '';
    logsAutoRefresh.value = false;
    if (logsInterval) {
        clearInterval(logsInterval);
        logsInterval = null;
    }
}

function toggleAutoRefresh() {
    logsAutoRefresh.value = !logsAutoRefresh.value;
    if (logsAutoRefresh.value) {
        logsInterval = setInterval(fetchLogs, 3000);
    } else if (logsInterval) {
        clearInterval(logsInterval);
        logsInterval = null;
    }
}

function getServiceIcon(meta: ServiceMeta) {
    return meta.icon;
}

onMounted(() => {
    loadStatus();
});

onUnmounted(() => {
    if (logsInterval) {
        clearInterval(logsInterval);
    }
});
</script>

<template>
    <Head :title="`Services - ${environment.name}`" />

    <div>
        <div class="mb-8 flex items-start justify-between">
            <div>
                <Heading title="Services" />
                <p class="text-zinc-400 mt-1">
                    <template v-if="loading">Loading services...</template>
                    <template v-else>{{ servicesRunning }}/{{ servicesTotal }} services running</template>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button
                    v-if="!allRunning"
                    @click="startAll"
                    :disabled="loading || actionInProgress !== null"
                    class="btn btn-secondary disabled:opacity-50"
                >
                    <Loader2 v-if="actionInProgress === 'start-all'" class="w-4 h-4 animate-spin" />
                    <Play v-else class="w-4 h-4" />
                    Start All
                </button>
                <button
                    v-if="!allStopped"
                    @click="stopAll"
                    :disabled="loading || actionInProgress !== null"
                    class="btn btn-outline disabled:opacity-50"
                >
                    <Loader2 v-if="actionInProgress === 'stop-all'" class="w-4 h-4 animate-spin" />
                    <Square v-else class="w-4 h-4" />
                    Stop All
                </button>
                <button
                    @click="restartAll"
                    :disabled="loading || actionInProgress !== null"
                    class="btn btn-outline disabled:opacity-50"
                >
                    <Loader2 v-if="restartingAll" class="w-4 h-4 animate-spin" />
                    <RefreshCw v-else class="w-4 h-4" />
                    Restart All
                </button>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="border border-zinc-800 rounded-lg p-8 text-center">
            <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-3" />
            <p class="text-zinc-500">Loading services...</p>
        </div>

        <!-- Services by Category -->
        <div v-else class="space-y-6">
            <template v-for="category in categories" :key="category.key">
                <div v-if="servicesByCategory[category.key].length > 0" class="border border-zinc-600/40 rounded-xl px-0.5 pt-4 pb-0.5 bg-zinc-800/40">
                    <div class="px-4 mb-4">
                        <h3 class="text-sm font-medium text-white">{{ category.label }}</h3>
                    </div>
                    <div class="border border-zinc-600/50 rounded-lg overflow-hidden divide-y divide-zinc-600/40">
                        <div
                            v-for="{ key, service, meta } in servicesByCategory[category.key]"
                            :key="key"
                            class="p-4 flex items-center justify-between bg-zinc-700/30"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="w-10 h-10 rounded-lg flex items-center justify-center"
                                    :class="service.status === 'running' ? 'bg-lime-500/10' : 'bg-zinc-700/50'"
                                >
                                    <component
                                        :is="getServiceIcon(meta)"
                                        class="w-5 h-5"
                                        :class="service.status === 'running' ? 'text-lime-400' : 'text-zinc-500'"
                                    />
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-white">{{ meta.name }}</span>
                                        <span
                                            class="w-2 h-2 rounded-full"
                                            :class="service.status === 'running' ? 'bg-lime-400' : 'bg-zinc-600'"
                                        />
                                    </div>
                                    <div class="text-sm text-zinc-400">
                                        {{ meta.description }}
                                        <span v-if="meta.ports" class="text-zinc-600"> · </span>
                                        <span v-if="meta.ports" class="font-mono text-xs">{{ meta.ports }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="text-xs px-2 py-1 rounded-full capitalize"
                                    :class="service.status === 'running'
                                        ? 'bg-lime-500/10 text-lime-400'
                                        : 'bg-zinc-700/50 text-zinc-400'"
                                >
                                    {{ service.status }}
                                </span>
                                <div class="flex items-center gap-1 ml-2">
                                    <button
                                        v-if="service.status !== 'running'"
                                        @click="serviceAction(key, 'start')"
                                        :disabled="actionInProgress !== null"
                                        class="btn btn-plain p-1.5 text-zinc-400 hover:text-lime-400 disabled:opacity-50"
                                        title="Start"
                                    >
                                        <Loader2 v-if="actionInProgress === `start-${key}`" class="w-4 h-4 animate-spin" />
                                        <Play v-else class="w-4 h-4" />
                                    </button>
                                    <button
                                        v-if="service.status === 'running'"
                                        @click="serviceAction(key, 'stop')"
                                        :disabled="actionInProgress !== null"
                                        class="btn btn-plain p-1.5 text-zinc-400 hover:text-red-400 disabled:opacity-50"
                                        title="Stop"
                                    >
                                        <Loader2 v-if="actionInProgress === `stop-${key}`" class="w-4 h-4 animate-spin" />
                                        <Square v-else class="w-4 h-4" />
                                    </button>
                                    <button
                                        @click="serviceAction(key, 'restart')"
                                        :disabled="actionInProgress !== null"
                                        class="btn btn-plain p-1.5 text-zinc-400 hover:text-white disabled:opacity-50"
                                        title="Restart"
                                    >
                                        <Loader2 v-if="actionInProgress === `restart-${key}`" class="w-4 h-4 animate-spin" />
                                        <RefreshCw v-else class="w-4 h-4" />
                                    </button>
                                    <button
                                        @click="openLogs(key)"
                                        class="btn btn-plain p-1.5 text-zinc-400 hover:text-white"
                                        title="View Logs"
                                    >
                                        <FileText class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Logs Modal -->
        <div
            v-if="showLogs"
            class="fixed inset-0 bg-black/50 flex items-center justify-center z-50"
            @click.self="closeLogs"
        >
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl w-full max-w-4xl max-h-[80vh] flex flex-col">
                <div class="flex items-center justify-between p-4 border-b border-zinc-800">
                    <div class="flex items-center gap-3">
                        <h3 class="text-lg font-medium text-white">
                            {{ serviceMeta[logsService!]?.name || logsService }} Logs
                        </h3>
                        <button
                            @click="fetchLogs"
                            :disabled="logsLoading"
                            class="btn btn-plain p-1.5 text-zinc-400 hover:text-white"
                            title="Refresh"
                        >
                            <Loader2 v-if="logsLoading" class="w-4 h-4 animate-spin" />
                            <RefreshCw v-else class="w-4 h-4" />
                        </button>
                        <button
                            @click="toggleAutoRefresh"
                            class="text-xs px-2 py-1 rounded-full"
                            :class="logsAutoRefresh
                                ? 'bg-lime-500/10 text-lime-400'
                                : 'bg-zinc-700/50 text-zinc-400 hover:text-white'"
                        >
                            {{ logsAutoRefresh ? 'Auto-refresh ON' : 'Auto-refresh' }}
                        </button>
                    </div>
                    <button @click="closeLogs" class="text-zinc-400 hover:text-white">
                        <X class="w-5 h-5" />
                    </button>
                </div>
                <div class="flex-1 overflow-auto p-4">
                    <pre class="text-xs text-zinc-300 font-mono whitespace-pre-wrap">{{ logs }}</pre>
                </div>
            </div>
        </div>
    </div>
</template>
