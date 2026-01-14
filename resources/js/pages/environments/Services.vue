<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Modal from '@/components/Modal.vue';
import AddServiceModal from '@/components/AddServiceModal.vue';
import ConfigureServiceModal from '@/components/ConfigureServiceModal.vue';
import {
    Loader2, Play, Square, RefreshCw, Server, Database, Mail, Globe,
    Wifi, Container, FileText, X, Settings, Trash2, Plus
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
    required?: boolean;
    can_configure?: boolean;
}

interface ServiceMeta {
    name: string;
    description: string;
    icon: any;
    ports?: string;
    category: 'core' | 'database' | 'php' | 'utility';
    required?: boolean;
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

// Modals
const showAddServiceModal = ref(false);
const showConfigureModal = ref(false);
const selectedService = ref<string | null>(null);

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
        required: true,
    },
    'caddy': {
        name: 'Caddy Web Server',
        description: 'HTTPS reverse proxy with automatic certificates',
        icon: Server,
        ports: '80, 443',
        category: 'core',
        required: true,
    },
    'postgres': {
        name: 'PostgreSQL',
        description: 'Relational database server',
        icon: Database,
        ports: '5432',
        category: 'database',
    },
    'mysql': {
        name: 'MySQL',
        description: 'Relational database server',
        icon: Database,
        ports: '3306',
        category: 'database',
    },
    'redis': {
        name: 'Redis',
        description: 'In-memory cache and message broker',
        icon: Database,
        ports: '6379',
        category: 'database',
        required: true,
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
        required: true,
    },
    'horizon': {
        name: 'Laravel Horizon',
        description: 'Queue worker management for Redis',
        icon: FileText,
        category: 'utility',
        required: true,
    },
};

function getServiceType(key: string) {
    if (key === 'caddy' || key.startsWith('php-') || key === 'horizon') {
        return 'host';
    }
    return 'docker';
}

function getServiceMeta(key: string): ServiceMeta {
    if (serviceMeta[key]) return serviceMeta[key];

    if (key.startsWith('php-')) {
        const version = key.replace('php-', '');
        let displayVersion = version;
        // Handle both '83' and '8.3' formats
        if (version.length === 2 && !version.includes('.')) {
            displayVersion = `${version.slice(0, 1)}.${version.slice(1)}`;
        }
        return {
            name: `PHP ${displayVersion}`,
            description: 'Native PHP-FPM service',
            icon: Container,
            category: 'php',
        };
    }

    return {
        name: key,
        description: 'Service',
        icon: Container,
        category: 'utility',
    };
}

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
        const meta = getServiceMeta(key);
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
    const type = getServiceType(serviceKey);
    const path = type === 'host' ? `/host-services/${serviceKey}/${action}` : `/services/${serviceKey}/${action}`;

    try {
        const response = await fetch(getApiUrl(path), {
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

async function removeService(serviceKey: string) {
    if (!confirm(`Are you sure you want to remove ${serviceKey}?`)) return;

    actionInProgress.value = `remove-${serviceKey}`;
    try {
        const response = await fetch(getApiUrl(`/services/${serviceKey}`), {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        });
        const result = await response.json();

        if (result.success) {
            await loadStatus(true);
        } else {
            alert('Failed to remove service: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to remove service');
    } finally {
        actionInProgress.value = null;
    }
}

function configureService(serviceKey: string) {
    selectedService.value = serviceKey;
    showConfigureModal.value = true;
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
        const type = getServiceType(logsService.value);
        const path = type === 'host' ? `/host-services/${logsService.value}/logs` : `/services/${logsService.value}/logs`;
        const response = await fetch(getApiUrl(path));
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
                    @click="showAddServiceModal = true"
                    class="btn btn-primary"
                    :disabled="loading"
                >
                    <Plus class="w-4 h-4" />
                    Add Service
                </button>
                <div class="w-px h-6 bg-zinc-800 mx-1" />
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
                            class="p-4 flex items-center justify-between bg-zinc-700/30 group"
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
                                            v-if="service.required || meta.required"
                                            class="text-[10px] font-bold uppercase tracking-tight px-1.5 py-0.5 rounded bg-zinc-800 text-zinc-500 border border-zinc-700"
                                        >
                                            Required
                                        </span>
                                        <span
                                            class="text-[10px] font-bold uppercase tracking-tight px-1.5 py-0.5 rounded border"
                                            :class="getServiceType(key) === 'host'
                                                ? 'bg-blue-500/10 text-blue-400 border-blue-500/20'
                                                : 'bg-purple-500/10 text-purple-400 border-purple-500/20'"
                                        >
                                            {{ getServiceType(key) === 'host' ? 'Host' : 'Docker' }}
                                        </span>
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
                                        @click="configureService(key)"
                                        class="btn btn-plain p-1.5 text-zinc-400 hover:text-white"
                                        title="Configure"
                                    >
                                        <Settings class="w-4 h-4" />
                                    </button>
                                    <button
                                        @click="openLogs(key)"
                                        class="btn btn-plain p-1.5 text-zinc-400 hover:text-white"
                                        title="View Logs"
                                    >
                                        <FileText class="w-4 h-4" />
                                    </button>
                                    <button
                                        v-if="!service.required && !meta.required"
                                        @click="removeService(key)"
                                        :disabled="actionInProgress !== null"
                                        class="btn btn-plain p-1.5 text-zinc-400 hover:text-red-400 disabled:opacity-50"
                                        title="Remove Service"
                                    >
                                        <Loader2 v-if="actionInProgress === `remove-${key}`" class="w-4 h-4 animate-spin" />
                                        <Trash2 v-else class="w-4 h-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Add Service Modal -->
        <AddServiceModal
            :show="showAddServiceModal"
            :get-api-url="getApiUrl"
            :csrf-token="csrfToken"
            @close="showAddServiceModal = false"
            @service-enabled="() => loadStatus()"
        />

        <!-- Configure Service Modal -->
        <ConfigureServiceModal
            :show="showConfigureModal"
            :service-name="selectedService"
            :get-api-url="getApiUrl"
            :csrf-token="csrfToken"
            @close="showConfigureModal = false"
            @config-updated="() => loadStatus()"
        />

        <!-- Logs Modal -->
        <Modal :show="showLogs" :title="`${serviceMeta[logsService!]?.name || logsService} Logs`" maxWidth="max-w-4xl" @close="closeLogs">
            <div class="flex flex-col max-h-[70vh]">
                <div class="flex items-center gap-3 px-6 py-3 border-b border-zinc-800 bg-zinc-900/50">
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
                <div class="flex-1 overflow-auto p-4 bg-black">
                    <pre class="text-xs text-zinc-300 font-mono whitespace-pre-wrap">{{ logs }}</pre>
                </div>
            </div>
        </Modal>
    </div>
</template>

