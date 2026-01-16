<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useServicesStore, type Service } from '@/stores/services';
import { useEcho } from '@/composables/useEcho';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import Modal from '@/components/Modal.vue';
import AddServiceModal from '@/components/AddServiceModal.vue';
import ConfigureServiceModal from '@/components/ConfigureServiceModal.vue';
import {
    Loader2,
    Play,
    Square,
    RefreshCw,
    Server,
    Database,
    Mail,
    Globe,
    Wifi,
    Container,
    FileText,
    X,
    Settings,
    Trash2,
    Plus,
} from 'lucide-vue-next';
import { Button, Badge, Input } from '@hardimpactdev/craft-ui';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    tld: string;
    is_local: boolean;
}

interface Editor {
    scheme: string;
    name: string;
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
    editor: Editor;
    localPhpIniPath: string | null;
    homebrewPrefix: string | null;
}>();

const store = useServicesStore();
const { connect, disconnect } = useEcho();

// Helper to get the API URL - uses remote API directly when available
const getApiUrl = (path: string) => {
    if (props.remoteApiUrl) {
        return `${props.remoteApiUrl}${path}`;
    }
    return `/api/environments/${props.environment.id}${path}`;
};

const baseApiUrl = computed(() => getApiUrl(''));

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

const services = computed(() => store.services);
const loading = ref(false); // We'll manage initial load state
const servicesRunning = computed(() => store.servicesRunning);
const servicesTotal = computed(() => store.servicesTotal);
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

// PHP Settings Modal
const showPhpSettings = ref(false);
const phpSettingsLoading = ref(false);
const phpSettingsSaving = ref(false);
const phpSettingsVersion = ref('');
const phpSettings = ref({
    upload_max_filesize: '2M',
    post_max_size: '8M',
    memory_limit: '128M',
    max_execution_time: '30',
    max_children: '5',
    start_servers: '2',
    min_spare_servers: '1',
    max_spare_servers: '3',
});

// Service metadata
const serviceMeta: Record<string, ServiceMeta> = {
    dns: {
        name: 'DNS Server',
        description: 'Resolves local domains to Orbit',
        icon: Globe,
        ports: '53',
        category: 'core',
        required: true,
    },
    caddy: {
        name: 'Caddy Web Server',
        description: 'HTTPS reverse proxy with automatic certificates',
        icon: Server,
        ports: '80, 443',
        category: 'core',
        required: true,
    },
    postgres: {
        name: 'PostgreSQL',
        description: 'Relational database server',
        icon: Database,
        ports: '5432',
        category: 'database',
    },
    mysql: {
        name: 'MySQL',
        description: 'Relational database server',
        icon: Database,
        ports: '3306',
        category: 'database',
    },
    redis: {
        name: 'Redis',
        description: 'In-memory cache and message broker',
        icon: Database,
        ports: '6379',
        category: 'database',
        required: true,
    },
    mailpit: {
        name: 'Mailpit',
        description: 'Email testing and capture',
        icon: Mail,
        ports: '1025, 8025',
        category: 'utility',
    },
    reverb: {
        name: 'Laravel Reverb',
        description: 'WebSocket server for real-time features',
        icon: Wifi,
        ports: '6001',
        category: 'utility',
        required: true,
    },
    horizon: {
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

const allRunning = computed(
    () => servicesRunning.value === servicesTotal.value && servicesTotal.value > 0,
);
const allStopped = computed(() => servicesRunning.value === 0);

function normalizePhpVersion(serviceKey: string): string | null {
    if (!serviceKey.startsWith('php-')) return null;

    const raw = serviceKey.replace('php-', '');

    if (raw.includes('.')) {
        return raw;
    }

    // php-83 -> 8.3
    if (raw.length === 2) {
        return `${raw.slice(0, 1)}.${raw.slice(1)}`;
    }

    return null;
}

const latestPhpVersion = computed(() => {
    const versions = servicesByCategory.value.php
        .map(({ key }) => normalizePhpVersion(key))
        .filter((v): v is string => v !== null);

    if (versions.length === 0) return null;

    versions.sort((a, b) => {
        const [aMajor, aMinor] = a.split('.').map(Number);
        const [bMajor, bMinor] = b.split('.').map(Number);

        if (aMajor !== bMajor) return aMajor - bMajor;
        return aMinor - bMinor;
    });

    return versions[versions.length - 1];
});

async function openExternal(url: string) {
    try {
        await fetch('/open-external', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ url }),
        });
    } catch {
        // Silent fail for opening URLs
    }
}

async function openPhpSettings() {
    const version = latestPhpVersion.value;

    if (!version) {
        toast.error('No PHP services detected');
        return;
    }

    phpSettingsVersion.value = version;
    phpSettingsLoading.value = true;
    showPhpSettings.value = true;

    try {
        const response = await fetch(getApiUrl(`/php/config/${version}`));
        if (!response.ok) throw new Error('Failed to fetch PHP settings');
        const data = await response.json();
        
        if (data.success && data.data?.settings) {
            phpSettings.value = { ...phpSettings.value, ...data.data.settings };
        }
    } catch (error) {
        toast.error(`Error loading PHP settings: ${error}`);
    } finally {
        phpSettingsLoading.value = false;
    }
}

async function savePhpSettings() {
    phpSettingsSaving.value = true;

    try {
        const response = await fetch(getApiUrl(`/php/config/${phpSettingsVersion.value}`), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(phpSettings.value),
        });

        if (!response.ok) throw new Error('Failed to save PHP settings');
        const data = await response.json();

        if (data.success) {
            toast.success('PHP Settings Saved', {
                description: 'PHP-FPM has been restarted with the new configuration.',
            });
            showPhpSettings.value = false;
        } else {
            toast.error('Failed to Save Settings', {
                description: data.error || 'An unknown error occurred.',
            });
        }
    } catch (error) {
        toast.error('Error Saving PHP Settings', {
            description: String(error),
        });
    } finally {
        phpSettingsSaving.value = false;
    }
}

async function loadStatus(silent = false) {
    if (!silent) {
        loading.value = true;
    }
    try {
        await store.fetchServices(baseApiUrl.value);
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
}

async function startAll() {
    actionInProgress.value = 'start-all';
    try {
        const result = await store.startAll(baseApiUrl.value);

        if (result.success) {
            toast.success('Services Starting', {
                description: 'All services are being started.',
            });
            await loadStatus(true);
        } else {
            toast.error('Failed to Start Services', {
                description: result.error || 'An unknown error occurred.',
            });
        }
    } catch {
        toast.error('Failed to Start Services', {
            description: 'Could not connect to the server.',
        });
    } finally {
        actionInProgress.value = null;
    }
}

async function stopAll() {
    actionInProgress.value = 'stop-all';
    try {
        const result = await store.stopAll(baseApiUrl.value);

        if (result.success) {
            toast.success('Services Stopping', {
                description: 'All services are being stopped.',
            });
            await loadStatus(true);
        } else {
            toast.error('Failed to Stop Services', {
                description: result.error || 'An unknown error occurred.',
            });
        }
    } catch {
        toast.error('Failed to Stop Services', {
            description: 'Could not connect to the server.',
        });
    } finally {
        actionInProgress.value = null;
    }
}

async function restartAll() {
    restartingAll.value = true;
    actionInProgress.value = 'restart-all';
    try {
        const result = await store.restartAll(baseApiUrl.value);

        if (result.success) {
            toast.success('Services Restarting', {
                description: 'All services are being restarted.',
            });
            await loadStatus(true);
        } else {
            toast.error('Failed to Restart Services', {
                description: result.error || 'An unknown error occurred.',
            });
        }
    } catch {
        toast.error('Failed to Restart Services', {
            description: 'Could not connect to the server.',
        });
    } finally {
        restartingAll.value = false;
        actionInProgress.value = null;
    }
}

async function serviceAction(serviceKey: string, action: 'start' | 'stop' | 'restart') {
    const type = getServiceType(serviceKey);

    try {
        let result;
        if (action === 'start') {
            result = await store.startService(serviceKey, baseApiUrl.value, type);
        } else if (action === 'stop') {
            result = await store.stopService(serviceKey, baseApiUrl.value, type);
        } else {
            result = await store.restartService(serviceKey, baseApiUrl.value, type);
        }

        if (result?.success) {
            if (result.jobId) {
                // Real-time update will handle the rest
            } else {
                await loadStatus(true);
            }
        } else {
            toast.error(`Failed to ${action} ${serviceKey}: ` + (result?.error || 'Unknown error'));
        }
    } catch (error) {
        toast.error(`Failed to ${action} ${serviceKey}`);
    }
}

async function removeService(serviceKey: string) {
    if (!confirm(`Are you sure you want to remove ${serviceKey}?`)) return;

    try {
        const result = await store.disableService(serviceKey, baseApiUrl.value);

        if (result?.success) {
            toast.success(`${serviceKey} disabled`);
            await loadStatus(true);
        } else {
            toast.error('Failed to remove service: ' + (result?.error || 'Unknown error'));
        }
    } catch {
        toast.error('Failed to remove service');
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
        const path =
            type === 'host'
                ? `/host-services/${logsService.value}/logs`
                : `/services/${logsService.value}/logs`;
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

interface ServiceStatusEvent {
    job_id: string | null;
    service: string;
    status: string;
    action: string;
    error?: string;
    timestamp: number;
}

onMounted(async () => {
    store.setActiveEnvironment(props.environment.id);

    // Show cached data immediately, refresh if stale
    if (store.isStale) {
        loadStatus();
    }

    // Connect Echo for real-time updates
    const echo = connect(props.environment);
    if (echo) {
        echo.channel('orbit').listen('.service.status.changed', (event: ServiceStatusEvent) => {
            store.handleServiceStatusChanged(
                event.job_id,
                event.service,
                event.status,
                event.error,
            );

            if (event.error) {
                toast.error(`Failed to ${event.action} ${event.service}: ${event.error}`);
            } else {
                toast.success(`${event.service} ${event.action} completed`);
            }
        });
    }

    // Recover any pending jobs
    store.recoverPendingJobs(baseApiUrl.value);
});

onUnmounted(() => {
    disconnect();
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
                    <template v-else
                        >{{ servicesRunning }}/{{ servicesTotal }} services running</template
                    >
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    @click="showAddServiceModal = true"
                    :disabled="loading"
                    size="sm"
                >
                    <Plus class="w-4 h-4" />
                    Add Service
                </Button>
                <div class="w-px h-5 bg-zinc-700 mx-1" />
                <Button
                    v-if="!allRunning"
                    @click="startAll"
                    :disabled="loading || actionInProgress !== null"
                    variant="outline"
                    size="sm"
                >
                    <Loader2 v-if="actionInProgress === 'start-all'" class="w-4 h-4 animate-spin" />
                    <Play v-else class="w-4 h-4" />
                    Start All
                </Button>
                <Button
                    v-if="!allStopped"
                    @click="stopAll"
                    :disabled="loading || actionInProgress !== null"
                    variant="outline"
                    size="sm"
                >
                    <Loader2 v-if="actionInProgress === 'stop-all'" class="w-4 h-4 animate-spin" />
                    <Square v-else class="w-4 h-4" />
                    Stop All
                </Button>
                <Button
                    @click="restartAll"
                    :disabled="loading || actionInProgress !== null"
                    variant="outline"
                    size="sm"
                >
                    <Loader2 v-if="restartingAll" class="w-4 h-4 animate-spin" />
                    <RefreshCw v-else class="w-4 h-4" />
                    Restart All
                </Button>
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
                <div
                    v-if="servicesByCategory[category.key].length > 0"
                    class="border border-zinc-600/40 rounded-xl px-0.5 pt-4 pb-0.5 bg-zinc-800/40"
                >
                    <div class="px-4 mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-medium text-white">{{ category.label }}</h3>
                        <Button
                            v-if="category.key === 'php'"
                            @click="openPhpSettings"
                            variant="ghost"
                            size="icon-sm"
                            title="PHP Settings"
                        >
                            <Settings class="w-4 h-4" />
                        </Button>
                    </div>
                    <div
                        class="border border-zinc-600/50 rounded-lg overflow-hidden divide-y divide-zinc-600/40"
                    >
                        <div
                            v-for="{ key, service, meta } in servicesByCategory[category.key]"
                            :key="key"
                            class="p-4 flex items-center justify-between bg-zinc-700/30 group"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="w-10 h-10 rounded-lg flex items-center justify-center"
                                    :class="
                                        service.status === 'running'
                                            ? 'bg-lime-500/10'
                                            : 'bg-zinc-700/50'
                                    "
                                >
                                    <component
                                        :is="getServiceIcon(meta)"
                                        class="w-5 h-5"
                                        :class="
                                            service.status === 'running'
                                                ? 'text-lime-400'
                                                : 'text-zinc-500'
                                        "
                                    />
                                </div>
                                <div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-white">{{ meta.name }}</span>
                                        <Badge
                                            v-if="service.required || meta.required"
                                            variant="secondary"
                                            class="text-[10px] font-bold uppercase tracking-tight"
                                        >
                                            Required
                                        </Badge>
                                        <Badge
                                            class="text-[10px] font-bold uppercase tracking-tight"
                                            :class="
                                                getServiceType(key) === 'host'
                                                    ? 'bg-blue-500/10 text-blue-400 border-blue-500/20'
                                                    : 'bg-purple-500/10 text-purple-400 border-purple-500/20'
                                            "
                                        >
                                            {{ getServiceType(key) === 'host' ? 'Host' : 'Docker' }}
                                        </Badge>
                                        <span
                                            class="w-2 h-2 rounded-full"
                                            :class="
                                                service.status === 'running'
                                                    ? 'bg-lime-400'
                                                    : 'bg-zinc-600'
                                            "
                                        />
                                    </div>
                                    <div class="text-sm text-zinc-400">
                                        {{ meta.description }}
                                        <span v-if="meta.ports" class="text-zinc-600"> · </span>
                                        <span v-if="meta.ports" class="font-mono text-xs">{{
                                            meta.ports
                                        }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="text-xs px-2 py-1 rounded-full capitalize"
                                    :class="
                                        service.status === 'running'
                                            ? 'bg-lime-500/10 text-lime-400'
                                            : 'bg-zinc-700/50 text-zinc-400'
                                    "
                                >
                                    {{ service.status }}
                                </span>
                                <div class="flex items-center gap-1 ml-2">
                                    <Button
                                        v-if="service.status !== 'running'"
                                        @click="serviceAction(key, 'start')"
                                        :disabled="store.isServicePending(key)"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-zinc-400 hover:text-lime-400"
                                        title="Start"
                                    >
                                        <Loader2
                                            v-if="store.isServicePending(key)"
                                            class="w-4 h-4 animate-spin"
                                        />
                                        <Play v-else class="w-4 h-4" />
                                    </Button>
                                    <Button
                                        v-if="service.status === 'running'"
                                        @click="serviceAction(key, 'stop')"
                                        :disabled="store.isServicePending(key)"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-zinc-400 hover:text-red-400"
                                        title="Stop"
                                    >
                                        <Loader2
                                            v-if="store.isServicePending(key)"
                                            class="w-4 h-4 animate-spin"
                                        />
                                        <Square v-else class="w-4 h-4" />
                                    </Button>
                                    <Button
                                        @click="serviceAction(key, 'restart')"
                                        :disabled="store.isServicePending(key)"
                                        variant="ghost"
                                        size="icon-sm"
                                        title="Restart"
                                    >
                                        <Loader2
                                            v-if="store.isServicePending(key)"
                                            class="w-4 h-4 animate-spin"
                                        />
                                        <RefreshCw v-else class="w-4 h-4" />
                                    </Button>
                                    <Button
                                        @click="configureService(key)"
                                        variant="ghost"
                                        size="icon-sm"
                                        title="Configure"
                                    >
                                        <Settings class="w-4 h-4" />
                                    </Button>
                                    <Button
                                        @click="openLogs(key)"
                                        variant="ghost"
                                        size="icon-sm"
                                        title="View Logs"
                                    >
                                        <FileText class="w-4 h-4" />
                                    </Button>
                                    <Button
                                        v-if="!service.required && !meta.required"
                                        @click="removeService(key)"
                                        :disabled="store.isServicePending(key)"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="text-zinc-400 hover:text-red-400"
                                        title="Remove Service"
                                    >
                                        <Loader2
                                            v-if="store.isServicePending(key)"
                                            class="w-4 h-4 animate-spin"
                                        />
                                        <Trash2 v-else class="w-4 h-4" />
                                    </Button>
                                </div>
                            </div>
                            <!-- Error message for this service -->
                            <div v-if="store.getServiceError(key)" class="px-4 pb-3 -mt-2">
                                <div
                                    class="text-xs text-red-400 bg-red-400/10 border border-red-400/20 rounded px-2 py-1 flex items-center justify-between"
                                >
                                    <span>Error: {{ store.getServiceError(key) }}</span>
                                    <button
                                        @click="store.clearServiceError(key)"
                                        class="text-red-400 hover:text-white"
                                    >
                                        <X class="w-3 h-3" />
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
        <Modal
            :show="showLogs"
            :title="`${serviceMeta[logsService!]?.name || logsService} Logs`"
            maxWidth="max-w-4xl"
            @close="closeLogs"
        >
            <div class="flex flex-col max-h-[70vh]">
                <div
                    class="flex items-center gap-3 px-6 py-3 border-b border-zinc-800 bg-zinc-900/50"
                >
                    <Button
                        @click="fetchLogs"
                        :disabled="logsLoading"
                        variant="ghost"
                        size="icon-sm"
                        title="Refresh"
                    >
                        <Loader2 v-if="logsLoading" class="w-4 h-4 animate-spin" />
                        <RefreshCw v-else class="w-4 h-4" />
                    </Button>
                    <button
                        @click="toggleAutoRefresh"
                        class="text-xs px-2 py-1 rounded-full"
                        :class="
                            logsAutoRefresh
                                ? 'bg-lime-500/10 text-lime-400'
                                : 'bg-zinc-700/50 text-zinc-400 hover:text-white'
                        "
                    >
                        {{ logsAutoRefresh ? 'Auto-refresh ON' : 'Auto-refresh' }}
                    </button>
                </div>
                <div class="flex-1 overflow-auto p-4 bg-black">
                    <pre class="text-xs text-zinc-300 font-mono whitespace-pre-wrap">{{
                        logs
                    }}</pre>
                </div>
            </div>
        </Modal>

        <!-- PHP Settings Modal -->
        <Modal
            :show="showPhpSettings"
            :title="`PHP ${phpSettingsVersion} Settings`"
            maxWidth="max-w-lg"
            @close="showPhpSettings = false"
        >
            <div class="p-6">
                <div v-if="phpSettingsLoading" class="py-8 text-center">
                    <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-3" />
                    <p class="text-zinc-500">Loading settings...</p>
                </div>
                <form v-else @submit.prevent="savePhpSettings" class="space-y-6">
                    <!-- php.ini settings -->
                    <div>
                        <h4 class="text-sm font-medium text-white mb-3">php.ini</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Upload Max Filesize</label>
                                <Input v-model="phpSettings.upload_max_filesize" class="w-full font-mono" />
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Post Max Size</label>
                                <Input v-model="phpSettings.post_max_size" class="w-full font-mono" />
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Memory Limit</label>
                                <Input v-model="phpSettings.memory_limit" class="w-full font-mono" />
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Max Execution Time (sec)</label>
                                <Input v-model="phpSettings.max_execution_time" class="w-full font-mono" />
                            </div>
                        </div>
                    </div>

                    <!-- php-fpm pool settings -->
                    <div>
                        <h4 class="text-sm font-medium text-white mb-3">PHP-FPM Pool</h4>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Max Children</label>
                                <Input v-model="phpSettings.max_children" type="number" class="w-full font-mono" />
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Start Servers</label>
                                <Input v-model="phpSettings.start_servers" type="number" class="w-full font-mono" />
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Min Spare Servers</label>
                                <Input v-model="phpSettings.min_spare_servers" type="number" class="w-full font-mono" />
                            </div>
                            <div>
                                <label class="block text-xs text-zinc-400 mb-1">Max Spare Servers</label>
                                <Input v-model="phpSettings.max_spare_servers" type="number" class="w-full font-mono" />
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end gap-3 pt-4 border-t border-zinc-800">
                        <Button type="button" variant="ghost" @click="showPhpSettings = false">
                            Cancel
                        </Button>
                        <Button type="submit" variant="secondary" :disabled="phpSettingsSaving">
                            <Loader2 v-if="phpSettingsSaving" class="w-4 h-4 animate-spin" />
                            {{ phpSettingsSaving ? 'Saving...' : 'Save Settings' }}
                        </Button>
                    </div>
                </form>
            </div>
        </Modal>
    </div>
</template>
