<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Heading } from '@hardimpactdev/liftoff-vue';
import {
    ChevronLeft, ChevronRight, Check, AlertTriangle, Loader2,
    Download, ExternalLink, Code, RefreshCw, Lock, LockOpen, X, Zap, Plus, Trash2
} from 'lucide-vue-next';

interface Server {
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

interface Service {
    status: string;
}

interface StatusData {
    services_running?: number;
    services_total?: number;
    services?: Record<string, Service>;
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
    server: Server;
    installation: Installation;
    editor: Editor;
}>();

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

// Connection status
const connectionStatus = ref<'idle' | 'testing' | 'success' | 'error'>('idle');
const connectionMessage = ref('Connection not tested');

// Services
const services = ref<Record<string, Service>>({});
const servicesRunning = ref(0);
const servicesTotal = ref(0);
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
    'dns': '53',
    'php-83': '-',
    'php-84': '-',
    'caddy': '80, 443',
    'postgres': '5432',
    'redis': '6379',
    'mailpit': '1025, 8025',
};

const serviceDescriptions: Record<string, string> = {
    'dns': 'DNS Server',
    'php-83': 'PHP 8.3 (FrankenPHP)',
    'php-84': 'PHP 8.4 (FrankenPHP)',
    'caddy': 'Web Server',
    'postgres': 'PostgreSQL Database',
    'redis': 'Redis Cache',
    'mailpit': 'Mail Catcher',
};

// API Functions
async function testConnection() {
    connectionStatus.value = 'testing';
    connectionMessage.value = 'Testing connection...';

    try {
        const response = await fetch(`/servers/${props.server.id}/test-connection`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        });
        const result = await response.json();

        connectionStatus.value = result.success ? 'success' : 'error';
        connectionMessage.value = result.message;
    } catch {
        connectionStatus.value = 'error';
        connectionMessage.value = 'Connection failed';
    }
}

async function loadStatus() {
    servicesLoading.value = true;
    try {
        const response = await fetch(`/servers/${props.server.id}/status`);
        const result = await response.json();

        if (result.success && result.data) {
            services.value = result.data.services || {};
            servicesRunning.value = result.data.services_running || 0;
            servicesTotal.value = result.data.services_total || 0;
        }
    } catch (error) {
        console.error('Failed to load status:', error);
    } finally {
        servicesLoading.value = false;
    }
}

async function loadSites() {
    sitesLoading.value = true;
    try {
        const response = await fetch(`/servers/${props.server.id}/sites`);
        const result = await response.json();

        if (result.success && result.data) {
            sites.value = result.data.sites || [];
        }
    } catch (error) {
        console.error('Failed to load sites:', error);
    } finally {
        sitesLoading.value = false;
    }
}

async function loadWorktrees() {
    try {
        const response = await fetch(`/servers/${props.server.id}/worktrees`);
        const result = await response.json();

        if (result.success && result.data?.worktrees) {
            const grouped: Record<string, Worktree[]> = {};
            for (const wt of result.data.worktrees) {
                if (!grouped[wt.site]) grouped[wt.site] = [];
                grouped[wt.site].push(wt);
            }
            worktrees.value = grouped;
        }
    } catch (error) {
        console.error('Failed to load worktrees:', error);
    }
}

async function loadConfig() {
    configLoading.value = true;
    try {
        const response = await fetch(`/servers/${props.server.id}/config`);
        const result = await response.json();

        if (result.success) {
            config.value = result.data;
        }
    } catch (error) {
        console.error('Failed to load config:', error);
    } finally {
        configLoading.value = false;
    }
}

async function restartAllServices() {
    restartingAll.value = true;
    try {
        const response = await fetch(`/servers/${props.server.id}/restart`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        });
        const result = await response.json();

        if (result.success) {
            await loadStatus();
        } else {
            alert('Failed to restart services: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to restart services');
    } finally {
        restartingAll.value = false;
    }
}

async function changePhpVersion(site: string, version: string) {
    try {
        const response = await fetch(`/servers/${props.server.id}/php`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ site, version }),
        });
        const result = await response.json();

        if (result.success) {
            await loadSites();
        } else {
            alert('Failed to change PHP version: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to change PHP version');
    }
}

async function resetPhpVersion(site: string) {
    try {
        const response = await fetch(`/servers/${props.server.id}/php/reset`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ site }),
        });
        const result = await response.json();

        if (result.success) {
            await loadSites();
        } else {
            alert('Failed to reset PHP version: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to reset PHP version');
    }
}

async function openSite(domain: string, isSecure: boolean) {
    const url = `${isSecure ? 'https' : 'http'}://${domain}`;
    try {
        await fetch('/open-external', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ url }),
        });
    } catch (error) {
        console.error('Failed to open URL:', error);
    }
}

async function openInEditor(path: string) {
    if (!path) {
        alert('No path available for this site');
        return;
    }

    let url;
    if (props.server.is_local) {
        url = `${props.editor.scheme}://file${path}`;
    } else {
        const sshHost = `${props.server.user}@${props.server.host}`;
        url = `${props.editor.scheme}://vscode-remote/ssh-remote+${sshHost}${path}?windowId=_blank`;
    }

    try {
        await fetch('/open-external', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ url }),
        });
    } catch (error) {
        console.error('Failed to open in editor:', error);
    }
}

async function unlinkWorktree(siteName: string, worktreeName: string) {
    if (!confirm(`Remove worktree "${worktreeName}" from ${siteName}? This will remove the subdomain routing.`)) {
        return;
    }

    try {
        const response = await fetch(`/servers/${props.server.id}/worktrees/unlink`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({ site: siteName, worktree: worktreeName }),
        });
        const result = await response.json();

        if (result.success) {
            await loadWorktrees();
            await loadSites();
        } else {
            alert('Failed to unlink worktree: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to unlink worktree');
    }
}

async function installCli() {
    cliInstalling.value = true;
    try {
        const response = await fetch('/cli/install', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
        });
        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            alert('Failed to install CLI: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to install CLI');
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
    const paths = editPaths.value.filter(p => p.trim() !== '');
    if (paths.length === 0) {
        alert('Please add at least one project path');
        return;
    }

    configSaving.value = true;
    try {
        const response = await fetch(`/servers/${props.server.id}/config`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
            body: JSON.stringify({
                paths,
                tld: editTld.value.trim() || 'test',
                default_php_version: editPhpVersion.value,
            }),
        });
        const result = await response.json();

        if (result.success) {
            config.value = result.data;
            configEditing.value = false;
            await loadSites();
        } else {
            alert('Failed to save config: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to save config');
    } finally {
        configSaving.value = false;
    }
}

onMounted(async () => {
    testConnection();
    if (props.installation.installed) {
        loadConfig();
        loadStatus();
        await loadWorktrees();
        loadSites();
    }
});
</script>

<template>
    <Head :title="server.name" />

    <div class="p-6">
        <!-- Back Link -->
        <div class="mb-6">
            <Link href="/servers" class="text-blue-600 hover:text-blue-800 flex items-center">
                <ChevronLeft class="w-4 h-4 mr-1" />
                Back to Environments
            </Link>
        </div>

        <!-- Header -->
        <div class="flex justify-between items-start mb-6">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-2xl font-bold text-gray-800 dark:text-white">{{ server.name }}</h2>
                    <span
                        v-if="config"
                        class="px-2.5 py-1 text-sm font-mono font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200 rounded-full"
                    >
                        .{{ tld }}
                    </span>
                </div>
                <p class="text-gray-500 dark:text-gray-400">
                    <template v-if="server.is_local">Local machine</template>
                    <template v-else>{{ server.user }}@{{ server.host }}:{{ server.port }}</template>
                </p>
            </div>
            <div class="flex space-x-2">
                <Link
                    :href="`/servers/${server.id}/edit`"
                    class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                    Edit
                </Link>
                <button
                    @click="testConnection"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                >
                    Test Connection
                </button>
            </div>
        </div>

        <!-- Connection Status -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 mb-6">
            <div class="flex items-center">
                <span
                    class="w-3 h-3 rounded-full mr-3"
                    :class="{
                        'bg-gray-300': connectionStatus === 'idle',
                        'bg-yellow-400 animate-pulse': connectionStatus === 'testing',
                        'bg-green-500': connectionStatus === 'success',
                        'bg-red-500': connectionStatus === 'error',
                    }"
                />
                <span class="text-gray-700 dark:text-gray-300">{{ connectionMessage }}</span>
            </div>
        </div>

        <!-- Launchpad Installation -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Launchpad Installation</h3>
            <template v-if="installation.installed">
                <div class="flex items-center text-green-600">
                    <Check class="w-5 h-5 mr-2" />
                    Installed at {{ installation.path }}
                </div>
                <p class="text-gray-500 dark:text-gray-400 mt-1">Version: {{ installation.version }}</p>
            </template>
            <template v-else>
                <div class="flex items-center text-yellow-600 mb-3">
                    <AlertTriangle class="w-5 h-5 mr-2" />
                    Launchpad CLI not found
                </div>
                <button
                    v-if="server.is_local"
                    @click="installCli"
                    :disabled="cliInstalling"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 flex items-center"
                >
                    <Loader2 v-if="cliInstalling" class="w-4 h-4 mr-2 animate-spin" />
                    <Download v-else class="w-4 h-4 mr-2" />
                    {{ cliInstalling ? 'Installing...' : 'Install Launchpad CLI' }}
                </button>
                <p v-else class="text-gray-500 dark:text-gray-400">
                    Install launchpad on this environment to manage sites.
                </p>
            </template>
        </div>

        <template v-if="installation.installed">
            <!-- Configuration -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Configuration</h3>
                    <button
                        v-if="!configEditing"
                        @click="startEditConfig"
                        class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                    >
                        Edit
                    </button>
                </div>

                <!-- Config Display -->
                <div v-if="!configEditing">
                    <div v-if="configLoading" class="text-gray-500 dark:text-gray-400 text-sm">
                        Loading configuration...
                    </div>
                    <div v-else-if="config" class="space-y-3">
                        <div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Project Paths:</span>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400 font-mono">
                                <div v-for="path in config.paths" :key="path">{{ path }}</div>
                                <div v-if="!config.paths?.length" class="text-gray-400">No paths configured</div>
                            </div>
                        </div>
                        <div class="flex space-x-8">
                            <div>
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">TLD:</span>
                                <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 font-mono">{{ config.tld || 'test' }}</span>
                            </div>
                            <div>
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Default PHP:</span>
                                <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 font-mono">{{ config.default_php_version || '8.4' }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Config Editor -->
                <div v-else class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Project Paths</label>
                        <div class="space-y-2">
                            <div v-for="(path, index) in editPaths" :key="index" class="flex items-center space-x-2">
                                <input
                                    v-model="editPaths[index]"
                                    type="text"
                                    placeholder="/home/user/projects"
                                    class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white font-mono"
                                />
                                <button @click="removePath(index)" class="text-red-500 hover:text-red-700 p-2">
                                    <Trash2 class="w-5 h-5" />
                                </button>
                            </div>
                        </div>
                        <button @click="addPath" class="mt-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 flex items-center">
                            <Plus class="w-4 h-4 mr-1" />
                            Add Path
                        </button>
                    </div>

                    <div>
                        <label for="config-tld" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">TLD</label>
                        <input
                            v-model="editTld"
                            type="text"
                            id="config-tld"
                            placeholder="test"
                            class="w-full max-w-xs px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white font-mono"
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Sites will be accessible at sitename.{{ editTld || 'test' }}</p>
                    </div>

                    <div>
                        <label for="config-php" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Default PHP Version</label>
                        <select
                            v-model="editPhpVersion"
                            id="config-php"
                            class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"
                        >
                            <option value="8.3">PHP 8.3</option>
                            <option value="8.4">PHP 8.4</option>
                        </select>
                    </div>

                    <div class="flex space-x-3">
                        <button
                            @click="saveConfig"
                            :disabled="configSaving"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 text-sm"
                        >
                            {{ configSaving ? 'Saving...' : 'Save Changes' }}
                        </button>
                        <button
                            @click="cancelEditConfig"
                            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 text-sm"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

            <!-- Services -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Services</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <template v-if="servicesLoading">Loading...</template>
                            <template v-else>{{ servicesRunning }}/{{ servicesTotal }} running</template>
                        </p>
                    </div>
                    <button
                        @click="restartAllServices"
                        :disabled="servicesLoading || restartingAll"
                        class="px-3 py-1 text-sm border border-gray-300 dark:border-gray-600 rounded text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50"
                    >
                        {{ restartingAll ? 'Restarting...' : 'Restart All' }}
                    </button>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    <div v-if="servicesLoading" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                        <Loader2 class="h-5 w-5 mx-auto mb-2 text-gray-400 animate-spin" />
                        Loading services...
                    </div>
                    <template v-else>
                        <div
                            v-for="(service, name) in services"
                            :key="name"
                            class="px-6 py-4 flex items-center justify-between"
                        >
                            <div class="flex items-center">
                                <span
                                    class="w-2.5 h-2.5 rounded-full mr-3"
                                    :class="service.status === 'running' ? 'bg-green-500' : 'bg-red-500'"
                                />
                                <div>
                                    <div class="font-medium text-gray-900 dark:text-white">
                                        {{ serviceDescriptions[name] || name }}
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ name }}
                                        <template v-if="servicePorts[name] && servicePorts[name] !== '-'">
                                            <span class="text-gray-400"> · </span>
                                            <span class="font-mono">{{ servicePorts[name] }}</span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <span
                                class="text-sm capitalize"
                                :class="service.status === 'running' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                            >
                                {{ service.status || 'unknown' }}
                            </span>
                        </div>
                        <div v-if="Object.keys(services).length === 0" class="px-6 py-4 text-gray-500">
                            No services found
                        </div>
                    </template>
                </div>
            </div>

            <!-- Sites -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white">Sites</h3>
                </div>
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Site</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">PHP</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Path</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <tr v-if="sitesLoading">
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                <Loader2 class="h-5 w-5 mx-auto mb-2 text-gray-400 animate-spin" />
                                Loading sites...
                            </td>
                        </tr>
                        <tr v-else-if="sites.length === 0">
                            <td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                No sites configured.
                            </td>
                        </tr>
                        <template v-else v-for="site in sites" :key="site.name">
                            <!-- Site Row -->
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <button
                                            v-if="worktrees[site.name]?.length"
                                            @click="toggleWorktrees(site.name)"
                                            class="mr-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        >
                                            <ChevronRight
                                                class="w-4 h-4 transform transition-transform"
                                                :class="{ 'rotate-90': expandedSites.has(site.name) }"
                                            />
                                        </button>
                                        <span v-else class="w-6 mr-2" />
                                        <Lock v-if="site.secure" class="w-4 h-4 mr-2 text-green-600" />
                                        <LockOpen v-else class="w-4 h-4 mr-2 text-gray-400" />
                                        <span class="font-medium text-gray-900 dark:text-white">{{ site.domain }}</span>
                                        <span
                                            v-if="worktrees[site.name]?.length"
                                            class="ml-2 px-1.5 py-0.5 text-xs font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-900 dark:text-indigo-300 rounded"
                                        >
                                            {{ worktrees[site.name].length }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <select
                                        :value="site.php_version || '8.4'"
                                        @change="changePhpVersion(site.name, ($event.target as HTMLSelectElement).value)"
                                        class="text-sm border border-gray-300 dark:border-gray-600 rounded px-2 py-1 bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-200"
                                    >
                                        <option value="8.3">PHP 8.3</option>
                                        <option value="8.4">PHP 8.4</option>
                                    </select>
                                    <button
                                        v-if="site.has_custom_php"
                                        @click="resetPhpVersion(site.name)"
                                        class="ml-1 text-xs text-gray-500 hover:text-red-600"
                                        title="Reset to default"
                                    >
                                        <RefreshCw class="w-4 h-4 inline" />
                                    </button>
                                </td>
                                <td class="px-6 py-4 text-gray-500 dark:text-gray-400 text-sm">{{ site.path }}</td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button
                                        @click="openSite(site.domain, site.secure ?? false)"
                                        class="inline-flex items-center px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700"
                                    >
                                        <ExternalLink class="w-4 h-4 mr-1" />
                                        Open
                                    </button>
                                    <button
                                        @click="openInEditor(site.path || '')"
                                        class="inline-flex items-center px-3 py-1 bg-gray-600 text-white text-sm rounded hover:bg-gray-700"
                                    >
                                        <Code class="w-4 h-4 mr-1" />
                                        {{ editor.name }}
                                    </button>
                                </td>
                            </tr>
                            <!-- Worktree Rows -->
                            <template v-if="expandedSites.has(site.name) && worktrees[site.name]?.length">
                                <tr
                                    v-for="wt in worktrees[site.name]"
                                    :key="`${site.name}-${wt.name}`"
                                    class="bg-gray-100 dark:bg-gray-900"
                                >
                                    <td class="px-6 py-3 pl-14">
                                        <div class="flex items-center">
                                            <Zap class="w-4 h-4 mr-2 text-indigo-600 dark:text-indigo-300" />
                                            <span class="font-medium text-gray-800 dark:text-white">{{ wt.domain }}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3 text-gray-600 dark:text-gray-300 text-xs font-mono">
                                        {{ wt.branch || '-' }}
                                    </td>
                                    <td class="px-6 py-3 text-gray-600 dark:text-gray-300 text-xs truncate max-w-xs" :title="wt.path">
                                        {{ wt.path }}
                                    </td>
                                    <td class="px-6 py-3 text-right space-x-2">
                                        <button
                                            @click="openSite(wt.domain, wt.secure ?? true)"
                                            class="inline-flex items-center px-2 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700"
                                        >
                                            Open
                                        </button>
                                        <button
                                            @click="openInEditor(wt.path)"
                                            class="inline-flex items-center px-2 py-1 bg-gray-600 text-white text-xs rounded hover:bg-gray-700"
                                        >
                                            {{ editor.name }}
                                        </button>
                                        <button
                                            @click="unlinkWorktree(site.name, wt.name)"
                                            class="inline-flex items-center px-2 py-1 text-red-600 hover:text-red-800 text-xs"
                                            title="Unlink worktree"
                                        >
                                            <X class="w-4 h-4" />
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
