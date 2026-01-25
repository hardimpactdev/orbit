<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import { useServicesStore, type Service } from '@/stores/services';
import { useEchoPublic, useConnectionStatus } from '@laravel/echo-vue';
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
    MoreHorizontal,
} from 'lucide-vue-next';
import { Button, Badge, Input, DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator } from '@hardimpactdev/craft-ui';

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
const page = usePage();
const connectionStatus = useConnectionStatus();

const reverbEnabled = computed(
    () => Boolean((page.props.reverb as { enabled?: boolean } | undefined)?.enabled),
);

const shouldWarnRealtime = ref(false);

watch(connectionStatus, (status) => {
    if (!reverbEnabled.value) {
        return;
    }

    if (status === 'failed' && !shouldWarnRealtime.value) {
        shouldWarnRealtime.value = true;
        toast.warning('Real-time updates unavailable', {
            description: 'Could not connect to Reverb. Status updates may be delayed.',
        });
    }
});

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
const logsClearedAt = ref<Date | null>(null);
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
        ports: '8080',
        category: 'utility',
        required: true,
    },
    horizon: {
        name: 'Laravel Horizon',
        description: 'Queue worker for production (orbit.ccc)',
        icon: FileText,
        category: 'utility',
        required: true,
    },
    'horizon-dev': {
        name: 'Laravel Horizon',
        description: 'Queue worker for development (orbit-web.ccc)',
        icon: FileText,
        category: 'utility',
        required: true,
    },
};

function getServiceType(key: string) {
    if (key === 'caddy' || key.startsWith('php-') || key === 'horizon' || key === 'horizon-dev') {
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

function openExternal(url: string) {
    window.open(url, '_blank');
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

function formatLogTimestamps(logContent: string): string {
    // Match ISO timestamps like 2026-01-21T23:21:17.678677551Z
    return logContent.replace(
        /(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):\d{2}\.\d+Z/g,
        (_, date, hour, minute) => `${date} ${hour}:${minute}`
    );
}

async function fetchLogs() {
    if (!logsService.value) return;

    logsLoading.value = true;
    try {
        const type = getServiceType(logsService.value);
        const basePath =
            type === 'host'
                ? `/host-services/${logsService.value}/logs`
                : `/services/${logsService.value}/logs`;
        
        // If logs were cleared, only fetch logs since that time
        let path = basePath;
        if (logsClearedAt.value) {
            const since = logsClearedAt.value.toISOString();
            path = `${basePath}?since=${encodeURIComponent(since)}`;
        }
        
        const response = await fetch(getApiUrl(path));
        const result = await response.json();

        if (result.success) {
            const logContent = formatLogTimestamps(result.logs || 'No logs available');
            if (logsClearedAt.value) {
                // Show cleared marker + new logs
                const clearedTime = logsClearedAt.value.toLocaleTimeString();
                logs.value = `--- Logs cleared at ${clearedTime} (showing only new entries) ---\n\n${logContent}`;
            } else {
                logs.value = logContent;
            }
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
    logsClearedAt.value = null;
    if (logsInterval) {
        clearInterval(logsInterval);
        logsInterval = null;
    }
}

function clearLogs() {
    logsClearedAt.value = new Date();
    logs.value = `--- Logs cleared at ${logsClearedAt.value.toLocaleTimeString()} ---\n\nWaiting for new log entries...`;
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

if (reverbEnabled.value) {
    useEchoPublic('orbit', '.service.status.changed', (event: ServiceStatusEvent) => {
        store.handleServiceStatusChanged(event.job_id, event.service, event.status, event.error);

        if (event.error) {
            toast.error(`Failed to ${event.action} ${event.service}: ${event.error}`);
        } else {
            toast.success(`${event.service} ${event.action} completed`);
        }
    });
}

onMounted(async () => {
    store.setActiveEnvironment(props.environment.id);

    // Show cached data immediately, refresh if stale
    if (store.isStale) {
        loadStatus();
    }

    // Recover any pending jobs
    store.recoverPendingJobs(baseApiUrl.value);
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
        <!-- Header -->
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">Services</h1>
                <p class="text-sm text-zinc-500 mt-1">
                    <template v-if="loading">Loading services...</template>
                    <template v-else>{{ servicesRunning }}/{{ servicesTotal }} services running</template>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button
                    @click="showAddServiceModal = true"
                    :disabled="loading"
                    size="sm"
                    class="bg-lime-500 hover:bg-lime-600 text-zinc-950"
                >
                    <Plus class="w-4 h-4 mr-1.5" />
                    Add Service
                </Button>
                <Button
                    v-if="!allStopped"
                    @click="stopAll"
                    :disabled="loading || actionInProgress !== null"
                    variant="secondary"
                    size="sm"
                >
                    <Loader2 v-if="actionInProgress === 'stop-all'" class="w-3.5 h-3.5 mr-1.5 animate-spin" />
                    <Square v-else class="w-3.5 h-3.5 mr-1.5" />
                    Stop All
                </Button>
                <Button
                    @click="restartAll"
                    :disabled="loading || actionInProgress !== null"
                    variant="secondary"
                    size="sm"
                >
                    <Loader2 v-if="restartingAll" class="w-3.5 h-3.5 mr-1.5 animate-spin" />
                    <RefreshCw v-else class="w-3.5 h-3.5 mr-1.5" />
                    Restart All
                </Button>
            </div>
        </header>

        <!-- Loading State -->
        <div v-if="loading" class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-8 text-center">
            <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-3" />
            <p class="text-zinc-500">Loading services...</p>
        </div>

        <!-- Services by Category -->
        <div v-else class="space-y-6">
            <template v-for="category in categories" :key="category.key">
                <div
                    v-if="servicesByCategory[category.key].length > 0"
                    class="rounded-lg border border-zinc-800 bg-zinc-900/50 overflow-hidden"
                >
                    <!-- Category Header -->
                    <div class="px-4 py-3 border-b border-zinc-800 bg-zinc-800/30">
                        <div class="flex items-center justify-between">
                            <h2 class="text-sm font-medium text-zinc-100">{{ category.label }}</h2>
                            <Button
                                v-if="category.key === 'php'"
                                @click="openPhpSettings"
                                variant="ghost"
                                size="icon-sm"
                                class="h-7 w-7 text-zinc-400 hover:text-zinc-100"
                                title="PHP Settings"
                            >
                                <Settings class="w-4 h-4" />
                            </Button>
                        </div>
                    </div>
                    <!-- Service Rows -->
                    <div class="divide-y divide-zinc-800/50">
                        <div
                            v-for="{ key, service, meta } in servicesByCategory[category.key]"
                            :key="key"
                            class="flex items-center gap-4 px-4 py-3 transition-colors hover:bg-zinc-800/30"
                        >
                            <!-- Service Icon -->
                            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-zinc-800/50 border border-zinc-700/50">
                                <component
                                    :is="getServiceIcon(meta)"
                                    class="h-4 w-4 text-zinc-400"
                                />
                            </div>

                            <!-- Service Info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium text-sm text-zinc-100">{{ meta.name }}</span>
                                    <span
                                        v-if="service.required || meta.required"
                                        class="px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide rounded-full bg-zinc-800/80 text-zinc-400 ring-1 ring-inset ring-zinc-700/50"
                                    >
                                        Required
                                    </span>
                                    <span
                                        class="px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide rounded-full ring-1 ring-inset"
                                        :class="
                                            getServiceType(key) === 'docker'
                                                ? 'bg-blue-500/10 text-blue-400 ring-blue-500/20'
                                                : 'bg-lime-500/10 text-lime-400 ring-lime-500/20'
                                        "
                                    >
                                        {{ getServiceType(key) }}
                                    </span>
                                    <span
                                        class="h-2 w-2 rounded-full"
                                        :class="
                                            service.status === 'running'
                                                ? 'bg-lime-400'
                                                : service.status === 'stopped'
                                                    ? 'bg-zinc-600'
                                                    : 'bg-red-400'
                                        "
                                    />
                                </div>
                                <p class="text-xs text-zinc-500 mt-0.5 truncate">
                                    {{ meta.description }}
                                    <span v-if="meta.ports" class="text-zinc-600/70"> · </span>
                                    <span v-if="meta.ports" class="text-zinc-500/70">{{ meta.ports }}</span>
                                </p>
                            </div>

                            <!-- Status & Actions -->
                            <div class="flex items-center gap-2">
                                <span
                                    class="px-2.5 py-1 text-xs font-medium rounded-full ring-1 ring-inset"
                                    :class="
                                        service.status === 'running'
                                            ? 'bg-lime-500/15 text-lime-400 ring-lime-500/30'
                                            : service.status === 'stopped'
                                                ? 'bg-zinc-800 text-zinc-400 ring-zinc-700'
                                                : 'bg-red-500/15 text-red-400 ring-red-500/30'
                                    "
                                >
                                    {{ service.status === 'running' ? 'Running' : service.status === 'stopped' ? 'Stopped' : 'Error' }}
                                </span>

                                <div class="flex items-center gap-0.5 opacity-40 hover:opacity-100 transition-opacity">
                                    <Button
                                        v-if="service.status !== 'running'"
                                        @click="serviceAction(key, 'start')"
                                        :disabled="store.isServicePending(key)"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="h-8 w-8 text-zinc-400 hover:text-lime-400 hover:bg-zinc-800"
                                        title="Start"
                                    >
                                        <Loader2
                                            v-if="store.isServicePending(key)"
                                            class="w-3.5 h-3.5 animate-spin"
                                        />
                                        <Play v-else class="w-3.5 h-3.5" />
                                    </Button>
                                    <Button
                                        v-if="service.status === 'running'"
                                        @click="serviceAction(key, 'stop')"
                                        :disabled="store.isServicePending(key)"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="h-8 w-8 text-zinc-400 hover:text-red-400 hover:bg-zinc-800"
                                        title="Stop"
                                    >
                                        <Loader2
                                            v-if="store.isServicePending(key)"
                                            class="w-3.5 h-3.5 animate-spin"
                                        />
                                        <Square v-else class="w-3.5 h-3.5" />
                                    </Button>
                                    <Button
                                        @click="serviceAction(key, 'restart')"
                                        :disabled="store.isServicePending(key)"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="h-8 w-8 text-zinc-400 hover:text-zinc-100 hover:bg-zinc-800"
                                        title="Restart"
                                    >
                                        <Loader2
                                            v-if="store.isServicePending(key)"
                                            class="w-3.5 h-3.5 animate-spin"
                                        />
                                        <RefreshCw v-else class="w-3.5 h-3.5" />
                                    </Button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                class="h-8 w-8 text-zinc-400 hover:text-zinc-100 hover:bg-zinc-800"
                                            >
                                                <MoreHorizontal class="w-4 h-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" class="w-40">
                                            <DropdownMenuItem @click="configureService(key)">
                                                <Settings class="w-4 h-4 mr-2" />
                                                Settings
                                            </DropdownMenuItem>
                                            <DropdownMenuItem @click="openLogs(key)">
                                                <FileText class="w-4 h-4 mr-2" />
                                                View Logs
                                            </DropdownMenuItem>
                                            <template v-if="!service.required && !meta.required">
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem 
                                                    @click="removeService(key)"
                                                    :disabled="store.isServicePending(key)"
                                                    class="text-red-400 focus:text-red-400"
                                                >
                                                    <Trash2 class="w-4 h-4 mr-2" />
                                                    Remove
                                                </DropdownMenuItem>
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        </div>
                        <!-- Error message for services with errors -->
                        <template v-for="{ key } in servicesByCategory[category.key]" :key="`error-${key}`">
                            <div v-if="store.getServiceError(key)" class="px-4 py-3 bg-red-500/5">
                                <div
                                    class="text-xs text-red-400 bg-red-400/10 border border-red-400/20 rounded px-3 py-2 flex items-center justify-between"
                                >
                                    <span>Error: {{ store.getServiceError(key) }}</span>
                                    <button
                                        @click="store.clearServiceError(key)"
                                        class="text-red-400 hover:text-white ml-2"
                                    >
                                        <X class="w-3 h-3" />
                                    </button>
                                </div>
                            </div>
                        </template>
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
            :environment-id="environment.id"
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
            noPadding
            @close="closeLogs"
        >
            <div class="flex flex-col h-[70vh] overflow-hidden">
                <div
                    class="flex items-center gap-3 px-4 py-3 border-b border-zinc-800 bg-zinc-900/50 shrink-0"
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
                    <Button
                        @click="clearLogs"
                        :disabled="logsLoading"
                        variant="ghost"
                        size="icon-sm"
                        title="Clear logs display"
                    >
                        <Trash2 class="w-4 h-4" />
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
                <div class="flex-1 overflow-auto bg-black">
                    <pre class="text-xs text-zinc-300 font-mono whitespace-pre-wrap break-all p-4 m-0">{{ logs }}</pre>
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
