<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
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
    HardDrive,
    Server,
} from 'lucide-vue-next';
import { Button, Badge, Input, Label, Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@hardimpactdev/craft-ui';

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

interface Project {
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
    projects?: Record<string, unknown>;
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

// Services (local state, no Pinia)
const services = ref<Record<string, { status: string; health: string | null }>>({});
const servicesLoading = ref(true);
const restartingAll = ref(false);
const servicesRunning = computed(() => Object.values(services.value).filter((s) => s.status === 'running').length);
const servicesTotal = computed(() => Object.keys(services.value).length);

// Projects
const projects = ref<Project[]>([]);
const projectsLoading = ref(true);
const worktrees = ref<Record<string, Worktree[]>>({});
const expandedProjects = ref<Set<string>>(new Set());

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
        const response = await fetch(`${getApiUrl('')}/status`, {
            signal: abortController.value?.signal,
        });
        const result = await response.json();
        if (result.success && result.data?.services) {
            services.value = result.data.services;
        } else if (result.services) {
            services.value = result.services;
        }
    } catch (error) {
        if (error instanceof Error && error.name === 'AbortError') return;
        console.error('Failed to load services status:', error);
    } finally {
        servicesLoading.value = false;
    }
}

async function loadProjects() {
    projectsLoading.value = true;
    try {
        const { data: result } = await api.get(getApiUrl('/projects'), {
            signal: abortController.value?.signal,
        });

        if (result.success && result.data) {
            projects.value = result.data.projects || [];
        }
    } catch (error) {
        if (axios.isCancel(error)) return;
    } finally {
        projectsLoading.value = false;
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
        const response = await fetch(`${getApiUrl('')}/restart`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            signal: abortController.value?.signal,
        });
        const result = await response.json();

        if (result.success) {
            toast.success('Services restarted successfully');
            await loadStatus();
        } else {
            toast.error('Failed to restart services', {
                description: result.error || 'Unknown error',
            });
        }
    } catch (error) {
        if (error instanceof Error && error.name === 'AbortError') return;
        toast.error('Failed to restart services');
    } finally {
        restartingAll.value = false;
    }
}

async function changePhpVersion(site: string, version: string) {
    try {
        const siteName = encodeURIComponent(site);
        const { data: result } = await api.post(`/projects/${siteName}/php`, { version });

        if (result.success) {
            await loadProjects();
        }
    } catch {
        // Error toast handled by axios interceptor
    }
}

async function resetPhpVersion(site: string) {
    try {
        const siteName = encodeURIComponent(site);
        const { data: result } = await api.post(`/projects/${siteName}/php/reset`, {});

        if (result.success) {
            await loadProjects();
        }
    } catch {
        // Error toast handled by axios interceptor
    }
}

function openSite(domain: string, isSecure: boolean) {
    const url = `${isSecure ? 'https' : 'http'}://${domain}`;
    window.open(url, '_blank');
}

function openInEditor(path: string) {
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
    window.open(url, '_blank');
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
            await loadProjects();
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
    if (expandedProjects.value.has(siteName)) {
        expandedProjects.value.delete(siteName);
    } else {
        expandedProjects.value.add(siteName);
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
            description: 'Please add at least one site path',
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
            await loadProjects();
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

    // Load all data in parallel
    // When remoteApiUrl is available, calls go directly to the remote server (bypasses NativePHP)
    // This eliminates the single-threaded PHP server bottleneck for remote environments
    testConnection();
    if (props.installation.installed) {
        loadConfig();
        loadStatus();
        loadProjects();
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
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
            <div class="flex items-center gap-3 min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-zinc-100 truncate">{{ environment.name }}</h1>
                <span v-if="config" class="px-2 py-0.5 text-xs font-mono bg-zinc-800 text-zinc-400 rounded-full flex-shrink-0">.{{ tld }}</span>
            </div>
            <Button @click="testConnection" variant="outline" size="sm" class="bg-transparent border-zinc-700 text-zinc-300 hover:bg-zinc-800 hover:text-zinc-100">
                <Loader2 v-if="connectionStatus === 'testing'" class="w-4 h-4 mr-1.5 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                Test Connection
            </Button>
        </header>

        <!-- Connection Status Banner -->
        <div class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-4 mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-lime-500/15">
                        <HardDrive v-if="environment.is_local" class="h-5 w-5 text-lime-400" aria-hidden="true" />
                        <Server v-else class="h-5 w-5 text-lime-400" aria-hidden="true" />
                    </div>
                    <div>
                        <p class="text-sm font-medium text-zinc-100 truncate max-w-[200px] sm:max-w-none">
                            {{ environment.is_local ? 'Local Machine' : `${environment.user}@${environment.host}` }}
                        </p>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span
                                class="relative flex h-2.5 w-2.5"
                            >
                                <span
                                    v-if="connectionStatus === 'success'"
                                    class="absolute inline-flex h-full w-full rounded-full bg-lime-400 opacity-75 animate-ping motion-reduce:animate-none"
                                />
                                <span
                                    class="relative inline-flex rounded-full h-2.5 w-2.5"
                                    :class="{
                                        'bg-zinc-600': connectionStatus === 'idle',
                                        'bg-amber-400': connectionStatus === 'testing',
                                        'bg-lime-400': connectionStatus === 'success',
                                        'bg-red-400': connectionStatus === 'error',
                                    }"
                                />
                            </span>
                            <span
                                class="text-sm"
                                :class="connectionStatus === 'success' ? 'text-zinc-100' : 'text-zinc-500'"
                            >
                                {{ connectionStatus === 'success' ? 'Connected' : connectionStatus === 'error' ? 'Disconnected' : connectionStatus === 'testing' ? 'Testing\u2026' : 'Not tested' }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="text-right hidden sm:block">
                    <p class="text-xs text-zinc-500">Last sync</p>
                    <p class="text-sm text-zinc-300">Just now</p>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div v-if="installation.installed" class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="flex flex-col gap-1 p-4 rounded-lg bg-zinc-800/30 border border-zinc-800">
                <span class="text-xs text-zinc-500 uppercase tracking-wide">Sites</span>
                <span class="text-2xl font-semibold text-zinc-100 tabular-nums">
                    <template v-if="projectsLoading">-</template>
                    <template v-else>{{ projects.length }}</template>
                </span>
            </div>
            <div class="flex flex-col gap-1 p-4 rounded-lg bg-zinc-800/30 border border-zinc-800">
                <span class="text-xs text-zinc-500 uppercase tracking-wide">Workspaces</span>
                <span class="text-2xl font-semibold text-zinc-100 tabular-nums">
                    <template v-if="projectsLoading">-</template>
                    <template v-else>{{ Object.keys(worktrees).length }}</template>
                </span>
            </div>
            <div class="flex flex-col gap-1 p-4 rounded-lg bg-zinc-800/30 border border-zinc-800">
                <span class="text-xs text-zinc-500 uppercase tracking-wide">Services</span>
                <span class="text-2xl font-semibold text-zinc-100 tabular-nums">
                    <template v-if="servicesLoading">-</template>
                    <template v-else>{{ servicesRunning }}/{{ servicesTotal }}</template>
                </span>
                <span v-if="!servicesLoading && servicesRunning === servicesTotal" class="text-xs text-zinc-500">All healthy</span>
            </div>
            <div class="flex flex-col gap-1 p-4 rounded-lg bg-zinc-800/30 border border-zinc-800">
                <span class="text-xs text-zinc-500 uppercase tracking-wide">Status</span>
                <span
                    class="text-2xl font-semibold tabular-nums"
                    :class="connectionStatus === 'success' ? 'text-lime-400' : connectionStatus === 'error' ? 'text-red-400' : 'text-zinc-500'"
                >
                    {{ connectionStatus === 'success' ? 'Online' : connectionStatus === 'error' ? 'Offline' : 'Checking' }}
                </span>
            </div>
        </div>

        <!-- Orbit Installation Card -->
        <div class="rounded-lg border border-zinc-800 bg-zinc-900/50 mb-6">
            <div class="flex items-center justify-between p-4 border-b border-zinc-800">
                <h2 class="text-sm font-medium text-zinc-100">Orbit Installation</h2>
                <Button v-if="installation.installed" variant="ghost" size="sm" class="h-7 px-2 text-xs text-zinc-400 hover:text-zinc-100">
                    <ExternalLink class="h-3.5 w-3.5 mr-1" aria-hidden="true" />
                    Open
                </Button>
            </div>
            <div class="p-4 space-y-4">
                <template v-if="installation.installed">
                    <div class="flex items-start gap-3">
                        <div class="flex h-5 w-5 items-center justify-center rounded-full bg-lime-500/15 mt-0.5">
                            <Check class="h-3 w-3 text-lime-400" aria-hidden="true" />
                        </div>
                        <div>
                            <p class="text-sm text-lime-400 font-medium break-all">{{ installation.path }}</p>
                            <p class="text-xs text-zinc-500 mt-1">Installation verified</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-6 text-sm">
                        <div>
                            <span class="text-zinc-500">Version:</span>
                            <span class="ml-1 text-zinc-300 font-mono">{{ installation.version }}</span>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="flex items-start gap-3">
                        <div class="flex h-5 w-5 items-center justify-center rounded-full bg-amber-500/15 mt-0.5">
                            <AlertTriangle class="h-3 w-3 text-amber-400" aria-hidden="true" />
                        </div>
                        <div>
                            <p class="text-sm text-amber-400 font-medium">Orbit CLI not found</p>
                            <p class="text-xs text-zinc-500 mt-1">Install to manage projects and services</p>
                        </div>
                    </div>
                    <Button
                        v-if="environment.is_local"
                        @click="installCli"
                        :disabled="cliInstalling"
                        variant="outline"
                        size="sm"
                        class="bg-transparent border-zinc-700 text-zinc-300 hover:bg-zinc-800"
                    >
                        <Loader2 v-if="cliInstalling" class="w-4 h-4 animate-spin motion-reduce:animate-none mr-1.5" aria-hidden="true" />
                        <Download v-else class="w-4 h-4 mr-1.5" aria-hidden="true" />
                        {{ cliInstalling ? 'Installing\u2026' : 'Install Orbit CLI' }}
                    </Button>
                    <p v-else class="text-zinc-500 text-sm">
                        Install orbit on this environment to manage projects.
                    </p>
                </template>
            </div>
        </div>

        <template v-if="installation.installed">
            <!-- Configuration Card -->
            <div class="rounded-lg border border-zinc-800 bg-zinc-900/50 mb-6">
                <div class="flex items-center justify-between p-4 border-b border-zinc-800">
                    <h2 class="text-sm font-medium text-zinc-100">Configuration</h2>
                    <Button
                        v-if="!configEditing"
                        @click="startEditConfig"
                        variant="ghost"
                        size="sm"
                        class="h-7 px-2 text-xs text-zinc-400 hover:text-zinc-100"
                    >
                        Edit
                    </Button>
                </div>

                <!-- Config Display -->
                <div v-if="!configEditing" class="p-4 space-y-4">
                    <div v-if="configLoading" class="text-zinc-500 text-sm">
                        Loading configuration\u2026
                    </div>
                    <template v-else-if="config">
                        <div>
                            <p class="text-xs text-zinc-500 mb-1">Site Paths</p>
                            <p class="text-sm text-zinc-100 font-mono bg-zinc-800/50 rounded px-2 py-1.5">
                                <template v-if="config.paths?.length">
                                    <span v-for="(path, i) in config.paths" :key="path">
                                        {{ path }}<br v-if="i < config.paths.length - 1" />
                                    </span>
                                </template>
                                <span v-else class="text-zinc-500">No paths configured</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-8">
                            <div>
                                <p class="text-xs text-zinc-500 mb-1">TLD</p>
                                <p class="text-sm text-zinc-100 font-mono">.{{ config.tld || 'test' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-zinc-500 mb-1">Default PHP</p>
                                <p class="text-sm text-zinc-100 font-mono">{{ config.default_php_version || '8.4' }}</p>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Config Editor -->
                <div v-else class="p-4 space-y-4">
                    <div>
                        <label class="block text-xs text-zinc-500 mb-2">Site Paths</label>
                        <div class="space-y-2">
                            <div
                                v-for="(path, index) in editPaths"
                                :key="index"
                                class="flex items-center gap-2"
                            >
                                <Input
                                    v-model="editPaths[index]"
                                    type="text"
                                    placeholder="/home/user/sites"
                                    autocomplete="off"
                                    class="flex-1 font-mono text-sm"
                                />
                                <button
                                    @click="removePath(index)"
                                    class="text-zinc-500 hover:text-red-400 focus-visible:text-red-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-400/50 p-2 rounded transition-colors"
                                    aria-label="Remove path"
                                >
                                    <Trash2 class="w-4 h-4" aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                        <button
                            @click="addPath"
                            class="mt-2 text-xs text-zinc-400 hover:text-zinc-200 transition-colors"
                        >
                            + Add path
                        </button>
                    </div>

                    <div>
                        <label for="config-tld" class="block text-xs text-zinc-500 mb-1">TLD</label>
                        <Input
                            v-model="editTld"
                            type="text"
                            id="config-tld"
                            name="tld"
                            placeholder="test"
                            autocomplete="off"
                            :spellcheck="false"
                            class="w-full max-w-xs font-mono text-sm"
                        />
                        <p class="mt-1 text-xs text-zinc-500">
                            Sites will be accessible at sitename.{{ editTld || 'test' }}
                        </p>
                    </div>

                    <div>
                        <label for="config-php" class="block text-xs text-zinc-500 mb-1">Default PHP Version</label>
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
                        <Button @click="saveConfig" :disabled="configSaving" size="sm" class="bg-lime-500 hover:bg-lime-600 text-zinc-950">
                            {{ configSaving ? 'Saving\u2026' : 'Save Changes' }}
                        </Button>
                        <Button @click="cancelEditConfig" variant="ghost" size="sm">Cancel</Button>
                    </div>
                </div>
            </div>




        </template>
    </div>
</template>
