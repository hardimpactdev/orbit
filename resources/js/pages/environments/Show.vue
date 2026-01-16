<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { useServicesStore } from '@/stores/services';
import { toast } from 'vue-sonner';
import axios from 'axios';
import api from '@/lib/axios';
import Heading from '@/components/Heading.vue';
import {
    ChevronRight,
    Check,
    AlertTriangle,
    Loader2,
    Download,
    ExternalLink,
    Code,
    RefreshCw,
    Lock,
    LockOpen,
    X,
    Zap,
    Plus,
    Trash2,
} from 'lucide-vue-next';
import { Button, Badge, Input, Label, Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableEmpty, Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@hardimpactdev/craft-ui';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    port: number;
    is_local: boolean;
}

interface Installation {
    installed: boolean;
    path?: string;
    version?: string;
}

interface Editor {
    scheme: string;
    name: string;
}

interface Site {
    name: string;
    domain: string;
    secure?: boolean;
    php_version?: string;
    has_custom_php?: boolean;
    path?: string;
}

interface Worktree {
    name: string;
    site: string;
    domain: string;
    branch?: string;
    path: string;
    secure?: boolean;
}

interface Config {
    paths: string[];
    tld: string;
    default_php_version: string;
    sites?: Record<string, unknown>;
}

const props = defineProps<{
    environment: Environment;
    installation: Installation;
    editor: Editor;
    remoteApiUrl: string | null; // Direct API URL for remote environments (bypasses NativePHP)
}>();

// Helper to get the API URL - uses remote API directly when available, falls back to NativePHP
const getApiUrl = (path: string) => {
    if (props.remoteApiUrl) {
        return `${props.remoteApiUrl}${path}`;
    }
    // Fallback to NativePHP backend for local environments or when TLD not set
    return `/api/environments/${props.environment.id}${path}`;
};

// AbortController to cancel in-flight requests when navigating away
const abortController = ref<AbortController | null>(null);

// Connection status
const connectionStatus = ref<'idle' | 'testing' | 'success' | 'error'>('idle');
const connectionMessage = ref('Connection not tested');

// Services
const servicesStore = useServicesStore();
const servicesLoading = ref(true);
const restartingAll = ref(false);

// Sites
const sites = ref<Site[]>([]);
const sitesLoading = ref(true);
const worktrees = ref<Record<string, Worktree[]>>({});
const expandedSites = ref<Set<string>>(new Set());

// Config
const config = ref<Config | null>(null);
const configLoading = ref(true);
const configEditing = ref(false);
const configSaving = ref(false);
const editPaths = ref<string[]>([]);
const editTld = ref('');
const editPhpVersion = ref('8.4');

// CLI install
const cliInstalling = ref(false);

const tld = computed(() => config.value?.tld || 'test');

const servicePorts: Record<string, string> = {
    dns: '53',
    'php-83': '-',
    'php-84': '-',
    caddy: '80, 443',
    postgres: '5432',
    redis: '6379',
    mailpit: '1025, 8025',
};

const serviceDescriptions: Record<string, string> = {
    dns: 'DNS Server',
    'php-83': 'PHP 8.3 (FrankenPHP)',
    'php-84': 'PHP 8.4 (FrankenPHP)',
    caddy: 'Web Server',
    postgres: 'PostgreSQL Database',
    redis: 'Redis Cache',
    mailpit: 'Mail Catcher',
};

// API Functions
async function testConnection() {
    connectionStatus.value = 'testing';
    connectionMessage.value = 'Testing connection...';

    try {
        // testConnection goes through NativePHP (tests SSH connection)
        const { data: result } = await api.post(
            `/api/environments/${props.environment.id}/test-connection`,
            {},
            {
                signal: abortController.value?.signal,
            },
        );

        connectionStatus.value = result.success ? 'success' : 'error';
        connectionMessage.value = result.message;
    } catch (error) {
        if (axios.isCancel(error)) return;
        connectionStatus.value = 'error';
        connectionMessage.value = 'Connection failed';
    }
}

async function loadStatus() {
    servicesLoading.value = true;
    try {
        await servicesStore.fetchServices(getApiUrl(''));
    } catch (error) {
        console.error('Failed to load services status:', error);
    } finally {
        servicesLoading.value = false;
    }
}

async function loadSites() {
    sitesLoading.value = true;
    try {
        const { data: result } = await api.get(getApiUrl('/sites'), {
            signal: abortController.value?.signal,
        });

        if (result.success && result.data) {
            sites.value = result.data.sites || [];
        }
    } catch (error) {
        if (axios.isCancel(error)) return;
    } finally {
        sitesLoading.value = false;
    }
}

async function loadWorktrees() {
    try {
        const { data: result } = await api.get(getApiUrl('/worktrees'), {
            signal: abortController.value?.signal,
        });

        if (result.success && result.data?.worktrees) {
            const grouped: Record<string, Worktree[]> = {};
            for (const wt of result.data.worktrees) {
                if (!grouped[wt.site]) grouped[wt.site] = [];
                grouped[wt.site].push(wt);
            }
            worktrees.value = grouped;
        }
    } catch (error) {
        if (axios.isCancel(error)) return;
    }
}

async function loadConfig() {
    configLoading.value = true;
    try {
        const { data: result } = await api.get(getApiUrl('/config'), {
            signal: abortController.value?.signal,
        });

        if (result.success) {
            config.value = result.data;
        }
    } catch (error) {
        if (axios.isCancel(error)) return;
    } finally {
        configLoading.value = false;
    }
}

async function restartAllServices() {
    restartingAll.value = true;
    try {
        const result = await servicesStore.restartAll(getApiUrl(''));

        if (result.success) {
            toast.success('Services restarted successfully');
            await loadStatus();
        } else {
            toast.error('Failed to restart services', {
                description: result.error || 'Unknown error',
            });
        }
    } catch {
        toast.error('Failed to restart services');
    } finally {
        restartingAll.value = false;
    }
}

async function changePhpVersion(site: string, version: string) {
    try {
        const { data: result } = await api.post(getApiUrl(`/php/${site}`), { version });

        if (result.success) {
            await loadSites();
        }
    } catch {
        // Error toast handled by axios interceptor
    }
}

async function resetPhpVersion(site: string) {
    try {
        const { data: result } = await api.post(getApiUrl(`/php/${site}/reset`), {});

        if (result.success) {
            await loadSites();
        }
    } catch {
        // Error toast handled by axios interceptor
    }
}

async function openSite(domain: string, isSecure: boolean) {
    const url = `${isSecure ? 'https' : 'http'}://${domain}`;
    try {
        await api.post('/open-external', { url });
    } catch {
        // Silent fail for opening URLs
    }
}

async function openInEditor(path: string) {
    if (!path) {
        toast.error('No path available for this site');
        return;
    }

    let url;
    if (props.environment.is_local) {
        url = `${props.editor.scheme}://file${path}`;
    } else {
        const sshHost = `${props.environment.user}@${props.environment.host}`;
        url = `${props.editor.scheme}://vscode-remote/ssh-remote+${sshHost}${path}?windowId=_blank`;
    }

    try {
        await api.post('/open-external', { url });
    } catch {
        // Silent fail for opening URLs
    }
}

async function unlinkWorktree(siteName: string, worktreeName: string) {
    if (
        !confirm(
            `Remove worktree "${worktreeName}" from ${siteName}? This will remove the subdomain routing.`,
        )
    ) {
        return;
    }

    try {
        const { data: result } = await api.delete(
            getApiUrl(`/worktrees/${siteName}/${worktreeName}`),
        );

        if (result.success) {
            await loadWorktrees();
            await loadSites();
        }
    } catch {
        // Error toast handled by axios interceptor
    }
}

async function installCli() {
    cliInstalling.value = true;
    try {
        const { data: result } = await api.post('/cli/install', {});

        if (result.success) {
            window.location.reload();
        } else {
            toast.error('Failed to install CLI', {
                description: result.error || 'Unknown error',
            });
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        cliInstalling.value = false;
    }
}

function toggleWorktrees(siteName: string) {
    if (expandedSites.value.has(siteName)) {
        expandedSites.value.delete(siteName);
    } else {
        expandedSites.value.add(siteName);
    }
}

function startEditConfig() {
    if (config.value) {
        editPaths.value = [...(config.value.paths || [])];
        if (editPaths.value.length === 0) editPaths.value.push('');
        editTld.value = config.value.tld || 'test';
        editPhpVersion.value = config.value.default_php_version || '8.4';
    }
    configEditing.value = true;
}

function cancelEditConfig() {
    configEditing.value = false;
}

function addPath() {
    editPaths.value.push('');
}

function removePath(index: number) {
    editPaths.value.splice(index, 1);
}

async function saveConfig() {
    const paths = editPaths.value.filter((p) => p.trim() !== '');
    if (paths.length === 0) {
        toast.error('Validation Error', {
            description: 'Please add at least one project path',
        });
        return;
    }

    configSaving.value = true;
    try {
        const { data: result } = await api.post(`/environments/${props.environment.id}/config`, {
            paths,
            tld: editTld.value.trim() || 'test',
            default_php_version: editPhpVersion.value,
        });

        if (result.success) {
            config.value = result.data;
            configEditing.value = false;
            toast.success('Configuration saved');
            await loadSites();
        } else {
            toast.error('Failed to save config', {
                description: result.error || 'Unknown error',
            });
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        configSaving.value = false;
    }
}

// Store cleanup function for the router listener
let removeRouterListener: (() => void) | null = null;

onMounted(() => {
    // Create abort controller for cancellable requests
    abortController.value = new AbortController();

    // Listen for Inertia navigation to abort in-flight requests
    removeRouterListener = router.on('before', () => {
        abortController.value?.abort();
    });

    // Set active environment in services store
    servicesStore.setActiveEnvironment(props.environment.id);

    // Load all data in parallel
    // When remoteApiUrl is available, calls go directly to the remote server (bypasses NativePHP)
    // This eliminates the single-threaded PHP server bottleneck for remote environments
    testConnection();
    if (props.installation.installed) {
        loadConfig();
        loadStatus();
        loadSites();
        loadWorktrees();
    }
});

onUnmounted(() => {
    // Clean up the router listener
    removeRouterListener?.();
    // Abort any remaining requests
    abortController.value?.abort();
});
</script>

<template>
    <Head :title="environment.name" />

    <div>
        <!-- Header -->
        <div class="flex justify-between items-start mb-8">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-2xl font-bold text-foreground">{{ environment.name }}</h2>
                    <Badge v-if="config" variant="secondary" class="font-mono">.{{ tld }}</Badge>
                </div>
                <p class="text-muted-foreground mt-1">
                    <template v-if="environment.is_local">Local machine</template>
                    <template v-else
                        >{{ environment.user }}@{{ environment.host }}:{{
                            environment.port
                        }}</template
                    >
                </p>
            </div>
            <Button @click="testConnection" variant="secondary">Test Connection</Button>
        </div>

        <!-- Connection Status -->
        <div class="border border-zinc-800 rounded-lg mb-6">
            <div class="p-5">
                <div class="flex items-center">
                    <span
                        class="w-2.5 h-2.5 rounded-full mr-3"
                        :class="{
                            'bg-zinc-600': connectionStatus === 'idle',
                            'bg-yellow-400 animate-pulse': connectionStatus === 'testing',
                            'bg-lime-400': connectionStatus === 'success',
                            'bg-red-400': connectionStatus === 'error',
                        }"
                    />
                    <span class="text-zinc-300 text-sm">{{ connectionMessage }}</span>
                </div>
            </div>
        </div>

        <!-- Orbit Installation -->
        <div class="border border-zinc-800 rounded-lg mb-6">
            <div class="p-5">
                <h3 class="text-sm font-medium text-white mb-4">Orbit Installation</h3>
                <template v-if="installation.installed">
                    <div class="flex items-center text-lime-400">
                        <Check class="w-4 h-4 mr-2" />
                        Installed at {{ installation.path }}
                    </div>
                    <p class="text-zinc-500 mt-1 text-sm">Version: {{ installation.version }}</p>
                </template>
                <template v-else>
                    <div class="flex items-center text-yellow-400 mb-3">
                        <AlertTriangle class="w-4 h-4 mr-2" />
                        Orbit CLI not found
                    </div>
                    <Button
                        v-if="environment.is_local"
                        @click="installCli"
                        :disabled="cliInstalling"
                        variant="secondary"
                    >
                        <Loader2 v-if="cliInstalling" class="w-4 h-4 animate-spin" />
                        <Download v-else class="w-4 h-4" />
                        {{ cliInstalling ? 'Installing...' : 'Install Orbit CLI' }}
                    </Button>
                    <p v-else class="text-zinc-500 text-sm">
                        Install orbit on this environment to manage sites.
                    </p>
                </template>
            </div>
        </div>

        <template v-if="installation.installed">
            <!-- Configuration -->
            <div class="border border-zinc-800 rounded-xl px-0.5 pt-4 pb-0.5 mb-6">
                <div class="flex justify-between items-center mb-4 px-4">
                    <h3 class="text-sm font-medium text-white">Configuration</h3>
                    <button
                        v-if="!configEditing"
                        @click="startEditConfig"
                        class="text-sm text-zinc-400 hover:text-white transition-colors"
                    >
                        Edit
                    </button>
                </div>

                <!-- Config Display -->
                <div
                    v-if="!configEditing"
                    class="border border-zinc-700/50 rounded-lg overflow-hidden space-y-px"
                >
                    <div v-if="configLoading" class="p-5 text-zinc-500 text-sm bg-zinc-800/30">
                        Loading configuration...
                    </div>
                    <template v-else-if="config">
                        <div class="p-5 bg-zinc-800/30">
                            <span class="text-sm font-medium text-zinc-400">Project Paths:</span>
                            <div class="mt-1 text-sm text-zinc-300 font-mono">
                                <div v-for="path in config.paths" :key="path">{{ path }}</div>
                                <div v-if="!config.paths?.length" class="text-zinc-500">
                                    No paths configured
                                </div>
                            </div>
                        </div>
                        <div class="p-5 bg-zinc-800/30 flex space-x-8">
                            <div>
                                <span class="text-sm font-medium text-zinc-400">TLD:</span>
                                <span class="ml-2 text-sm text-zinc-300 font-mono">{{
                                    config.tld || 'test'
                                }}</span>
                            </div>
                            <div>
                                <span class="text-sm font-medium text-zinc-400">Default PHP:</span>
                                <span class="ml-2 text-sm text-zinc-300 font-mono">{{
                                    config.default_php_version || '8.4'
                                }}</span>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Config Editor -->
                <div v-else class="space-y-4 px-4 pb-4">
                    <div>
                        <label class="block text-sm font-medium text-zinc-400 mb-2"
                            >Project Paths</label
                        >
                        <div class="space-y-2">
                            <div
                                v-for="(path, index) in editPaths"
                                :key="index"
                                class="flex items-center space-x-2"
                            >
                                <Input
                                    v-model="editPaths[index]"
                                    type="text"
                                    placeholder="/home/user/projects"
                                    class="flex-1 font-mono"
                                />
                                <button
                                    @click="removePath(index)"
                                    class="text-zinc-500 hover:text-red-400 p-2 transition-colors"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                        <button
                            @click="addPath"
                            class="mt-2 text-sm text-zinc-400 hover:text-white transition-colors"
                        >
                            + Add path
                        </button>
                    </div>

                    <div>
                        <label for="config-tld" class="block text-sm font-medium text-zinc-400 mb-1"
                            >TLD</label
                        >
                        <Input
                            v-model="editTld"
                            type="text"
                            id="config-tld"
                            placeholder="test"
                            class="w-full max-w-xs font-mono"
                        />
                        <p class="mt-1 text-xs text-zinc-500">
                            Sites will be accessible at sitename.{{ editTld || 'test' }}
                        </p>
                    </div>

                    <div>
                        <label for="config-php" class="block text-sm font-medium text-zinc-400 mb-1"
                            >Default PHP Version</label
                        >
                        <Select v-model="editPhpVersion">
                            <SelectTrigger class="w-full max-w-xs">
                                <SelectValue placeholder="Select PHP version" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="8.3">PHP 8.3</SelectItem>
                                <SelectItem value="8.4">PHP 8.4</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <Button
                            @click="saveConfig"
                            :disabled="configSaving"
                            variant="secondary"
                        >
                            {{ configSaving ? 'Saving...' : 'Save changes' }}
                        </Button>
                        <Button @click="cancelEditConfig" variant="ghost">Cancel</Button>
                    </div>
                </div>
            </div>

            <!-- Services -->
            <div class="border border-zinc-800 rounded-xl px-0.5 pt-4 pb-0.5 mb-6">
                <div class="flex justify-between items-center mb-4 px-4">
                    <div>
                        <h3 class="text-sm font-medium text-white">Services</h3>
                        <p class="text-sm text-zinc-500">
                            <template v-if="servicesLoading">Loading...</template>
                            <template v-else
                                >{{ servicesStore.servicesRunning }}/{{
                                    servicesStore.servicesTotal
                                }}
                                running</template
                            >
                        </p>
                    </div>
                    <Button
                        @click="restartAllServices"
                        :disabled="servicesLoading || restartingAll"
                        variant="outline"
                        size="sm"
                    >
                        {{ restartingAll ? 'Restarting...' : 'Restart All' }}
                    </Button>
                </div>
                <div class="border border-zinc-700/50 rounded-lg overflow-hidden space-y-px">
                    <div
                        v-if="servicesLoading"
                        class="p-5 text-center text-zinc-500 bg-zinc-800/30"
                    >
                        <Loader2 class="h-5 w-5 mx-auto mb-2 text-zinc-600 animate-spin" />
                        Loading services...
                    </div>
                    <template v-else>
                        <div
                            v-for="(service, name) in servicesStore.services"
                            :key="name"
                            class="p-5 flex items-center justify-between bg-zinc-800/30"
                        >
                            <div class="flex items-center">
                                <span
                                    class="w-2 h-2 rounded-full mr-3"
                                    :class="
                                        service.status === 'running' ? 'bg-lime-400' : 'bg-red-400'
                                    "
                                />
                                <div>
                                    <div class="font-medium text-white text-sm">
                                        {{ serviceDescriptions[name] || name }}
                                    </div>
                                    <div class="text-xs text-zinc-500">
                                        {{ name }}
                                        <template
                                            v-if="servicePorts[name] && servicePorts[name] !== '-'"
                                        >
                                            <span class="text-zinc-600"> · </span>
                                            <span class="font-mono">{{ servicePorts[name] }}</span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <span
                                class="text-xs capitalize"
                                :class="
                                    service.status === 'running' ? 'text-lime-400' : 'text-red-400'
                                "
                            >
                                {{ service.status || 'unknown' }}
                            </span>
                        </div>
                        <div
                            v-if="servicesStore.servicesTotal === 0"
                            class="p-5 text-zinc-500 text-sm bg-zinc-800/30"
                        >
                            No services found
                        </div>
                    </template>
                </div>
            </div>

            <!-- Sites -->
            <div class="border border-zinc-800 rounded-xl px-0.5 pt-4 pb-0.5 mb-6">
                <div class="flex justify-between items-center mb-4 px-4">
                    <h3 class="text-sm font-medium text-white">Sites</h3>
                    <Button as-child variant="secondary" size="sm">
                        <Link :href="`/environments/${environment.id}/projects/create`">
                            <Plus class="w-3.5 h-3.5" />
                            New Project
                        </Link>
                    </Button>
                </div>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-64">Site</TableHead>
                            <TableHead class="w-32">PHP</TableHead>
                            <TableHead>Path</TableHead>
                            <TableHead class="text-right w-48">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-if="sitesLoading">
                            <TableCell colspan="4" class="text-center py-8 text-muted-foreground">
                                <Loader2 class="h-5 w-5 mx-auto mb-2 text-zinc-600 animate-spin" />
                                Loading sites...
                            </TableCell>
                        </TableRow>
                        <TableEmpty v-else-if="sites.length === 0" :colspan="4">
                            No sites configured.
                        </TableEmpty>
                        <template v-else v-for="site in sites" :key="site.name">
                            <!-- Site Row -->
                            <TableRow>
                                <TableCell class="w-64">
                                    <div class="flex items-center gap-2">
                                        <button
                                            v-if="worktrees[site.name]?.length"
                                            @click="toggleWorktrees(site.name)"
                                            class="text-zinc-500 hover:text-white transition-colors flex-shrink-0"
                                        >
                                            <ChevronRight
                                                class="w-4 h-4 transform transition-transform"
                                                :class="{
                                                    'rotate-90': expandedSites.has(site.name),
                                                }"
                                            />
                                        </button>
                                        <span v-else class="w-4 flex-shrink-0" />
                                        <Lock
                                            v-if="site.secure"
                                            class="w-4 h-4 text-lime-400 flex-shrink-0"
                                        />
                                        <LockOpen
                                            v-else
                                            class="w-4 h-4 text-zinc-600 flex-shrink-0"
                                        />
                                        <span class="font-medium text-white">{{
                                            site.domain
                                        }}</span>
                                        <Badge
                                            v-if="worktrees[site.name]?.length"
                                            variant="secondary"
                                            class="flex-shrink-0"
                                        >
                                            {{ worktrees[site.name].length }}
                                        </Badge>
                                    </div>
                                </TableCell>
                                <TableCell class="w-32">
                                    <div class="flex items-center gap-1">
                                        <Select
                                            :model-value="site.php_version || '8.4'"
                                            @update:model-value="(v) => changePhpVersion(site.name, String(v))"
                                        >
                                            <SelectTrigger class="h-7 text-xs px-2" size="sm">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="8.3">PHP 8.3</SelectItem>
                                                <SelectItem value="8.4">PHP 8.4</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <button
                                            v-if="site.has_custom_php"
                                            @click="resetPhpVersion(site.name)"
                                            class="text-zinc-500 hover:text-red-400 transition-colors"
                                            title="Reset to default"
                                        >
                                            <RefreshCw class="w-3.5 h-3.5" />
                                        </button>
                                    </div>
                                </TableCell>
                                <TableCell class="text-muted-foreground text-sm font-mono">
                                    {{ site.path }}
                                </TableCell>
                                <TableCell class="text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <Button
                                            @click="openSite(site.domain, site.secure ?? false)"
                                            variant="secondary"
                                            size="sm"
                                        >
                                            <ExternalLink class="w-3.5 h-3.5" />
                                            Open
                                        </Button>
                                        <Button
                                            @click="openInEditor(site.path || '')"
                                            variant="outline"
                                            size="sm"
                                        >
                                            <Code class="w-3.5 h-3.5" />
                                            {{ editor.name }}
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <!-- Worktree Rows -->
                            <template
                                v-if="expandedSites.has(site.name) && worktrees[site.name]?.length"
                            >
                                <TableRow
                                    v-for="wt in worktrees[site.name]"
                                    :key="`${site.name}-${wt.name}`"
                                >
                                    <TableCell class="pl-12">
                                        <div class="flex items-center">
                                            <Zap class="w-4 h-4 mr-2 text-blue-400" />
                                            <span class="font-medium text-zinc-300">{{
                                                wt.domain
                                            }}</span>
                                        </div>
                                    </TableCell>
                                    <TableCell class="text-muted-foreground text-xs font-mono">
                                        {{ wt.branch || '-' }}
                                    </TableCell>
                                    <TableCell
                                        class="text-muted-foreground text-xs truncate max-w-xs font-mono"
                                        :title="wt.path"
                                    >
                                        {{ wt.path }}
                                    </TableCell>
                                    <TableCell class="text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <Button
                                                @click="openSite(wt.domain, wt.secure ?? true)"
                                                variant="ghost"
                                                size="sm"
                                            >
                                                Open
                                            </Button>
                                            <Button
                                                @click="openInEditor(wt.path)"
                                                variant="ghost"
                                                size="sm"
                                            >
                                                {{ editor.name }}
                                            </Button>
                                            <Button
                                                @click="unlinkWorktree(site.name, wt.name)"
                                                variant="ghost"
                                                size="icon-sm"
                                                class="text-zinc-500 hover:text-red-400"
                                                title="Unlink worktree"
                                            >
                                                <X class="w-4 h-4" />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </template>
                        </template>
                    </TableBody>
                </Table>
            </div>
        </template>
    </div>
</template>
