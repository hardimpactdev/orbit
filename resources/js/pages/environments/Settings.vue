<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Loader2, Trash2, Plus, AlertTriangle, Workflow, Check, AlertCircle, Stethoscope, RefreshCw, CheckCircle2, XCircle, AlertTriangleIcon, ChevronDown, ChevronRight } from 'lucide-vue-next';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    port: number;
    is_local: boolean;
    orchestrator_url: string | null;
}

interface Config {
    paths: string[];
    tld: string;
    default_php_version: string;
    available_php_versions?: string[];
}

const props = defineProps<{
    environment: Environment;
}>();

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

// Environment form
const envForm = useForm({
    name: props.environment.name,
    host: props.environment.host,
    user: props.environment.user,
    port: props.environment.port,
});

// CLI config
const config = ref<Config | null>(null);
const configLoading = ref(true);
const configSaving = ref(false);
const editPaths = ref<string[]>([]);
const editTld = ref('');
const editPhpVersion = ref('8.4');
const availablePhpVersions = ref<string[]>(['8.3', '8.4', '8.5']);

// Delete confirmation
const showDeleteConfirm = ref(false);
const deleteConfirmName = ref('');

// Orchestrator state
const orchestratorBusy = ref(false);
const orchestratorStatus = ref<string | null>(null);
const orchestratorError = ref<string | null>(null);
const tld = ref('test');

const isOrchestratorEnabled = computed(() => !!props.environment.orchestrator_url);

// Doctor/Health Check state
interface HealthCheck {
    status: 'ok' | 'warning' | 'error';
    message: string;
    details?: Record<string, unknown>;
}

interface DoctorResult {
    success: boolean;
    status: 'healthy' | 'degraded' | 'unhealthy';
    checks: Record<string, HealthCheck>;
    summary: {
        passed: number;
        warnings: number;
        errors: number;
        total: number;
        messages: string[];
    };
}

const doctorRunning = ref(false);
const doctorResult = ref<DoctorResult | null>(null);
const doctorError = ref<string | null>(null);
const expandedChecks = ref<Set<string>>(new Set());

const checkLabels: Record<string, string> = {
    ssh: 'SSH Connection',
    cli: 'CLI Installation',
    docker: 'Docker Services',
    api: 'API Connectivity',
    environment_dns: 'Environment DNS',
    local_dns: 'Local DNS',
    config: 'Configuration',
};

function toggleCheckExpanded(key: string) {
    if (expandedChecks.value.has(key)) {
        expandedChecks.value.delete(key);
    } else {
        expandedChecks.value.add(key);
    }
}

const fixingChecks = ref<Set<string>>(new Set());

async function runDoctor() {
    doctorRunning.value = true;
    doctorError.value = null;
    doctorResult.value = null;

    try {
        const response = await fetch(`/environments/${props.environment.id}/doctor`);
        const result = await response.json();

        if (result.success) {
            doctorResult.value = result;
        } else {
            doctorError.value = result.error || 'Health check failed';
        }
    } catch (error) {
        doctorError.value = 'Failed to run health check';
    } finally {
        doctorRunning.value = false;
    }
}

async function fixIssue(checkKey: string) {
    fixingChecks.value.add(checkKey);

    try {
        const response = await fetch(`/environments/${props.environment.id}/doctor/fix/${checkKey}`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/json',
            },
        });
        const result = await response.json();

        if (result.success) {
            // Re-run doctor to show updated status
            await runDoctor();
        } else {
            doctorError.value = result.message || 'Failed to fix issue';
        }
    } catch (error) {
        doctorError.value = 'Failed to fix issue';
    } finally {
        fixingChecks.value.delete(checkKey);
    }
}

async function enableOrchestrator() {
    orchestratorBusy.value = true;
    orchestratorError.value = null;
    orchestratorStatus.value = 'Scanning for existing installations...';

    try {
        // Step 1: Detect existing installations
        const detectResponse = await fetch(`/environments/${props.environment.id}/orchestrator/detect`);
        const detectResult = await detectResponse.json();

        if (detectResult.success) {
            tld.value = detectResult.tld || 'test';

            // Step 2: If found, reconcile it
            if (detectResult.installations && detectResult.installations.length > 0) {
                orchestratorStatus.value = 'Found existing installation, linking...';
                const installation = detectResult.installations[0];

                const reconcileResponse = await fetch(`/environments/${props.environment.id}/orchestrator/reconcile`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ path: installation.path }),
                });

                const reconcileResult = await reconcileResponse.json();

                if (reconcileResult.success) {
                    orchestratorStatus.value = 'Orchestrator enabled!';
                    setTimeout(() => router.reload(), 1000);
                    return;
                } else {
                    orchestratorError.value = reconcileResult.error || 'Failed to link orchestrator';
                    orchestratorStatus.value = null;
                    orchestratorBusy.value = false;
                    return;
                }
            }

            // Step 3: If not found, install new
            orchestratorStatus.value = 'Installing orchestrator...';

            const installResponse = await fetch(`/environments/${props.environment.id}/orchestrator/install`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                },
            });

            const installResult = await installResponse.json();

            if (installResult.success) {
                orchestratorStatus.value = 'Orchestrator installed and enabled!';
                setTimeout(() => router.reload(), 1000);
            } else {
                orchestratorError.value = installResult.error || 'Installation failed';
                orchestratorStatus.value = null;
                orchestratorBusy.value = false;
            }
        } else {
            orchestratorError.value = detectResult.error || 'Failed to scan for installations';
            orchestratorStatus.value = null;
            orchestratorBusy.value = false;
        }
    } catch (error) {
        orchestratorError.value = 'Failed to enable orchestrator';
        orchestratorStatus.value = null;
        orchestratorBusy.value = false;
    }
}

function disableOrchestrator() {
    router.post(`/environments/${props.environment.id}/orchestrator/disable`, {}, {
        preserveScroll: true,
    });
}

async function loadConfig() {
    configLoading.value = true;
    try {
        const response = await fetch(`/environments/${props.environment.id}/config`);
        const result = await response.json();

        if (result.success) {
            config.value = result.data;
            editPaths.value = [...(result.data.paths || [])];
            if (editPaths.value.length === 0) editPaths.value.push('');
            editTld.value = result.data.tld || 'test';
            editPhpVersion.value = result.data.default_php_version || '8.4';
            tld.value = result.data.tld || 'test';
            if (result.data.available_php_versions?.length) {
                availablePhpVersions.value = result.data.available_php_versions;
            }
        }
    } catch (error) {
        console.error('Failed to load config:', error);
    } finally {
        configLoading.value = false;
    }
}

function saveEnvSettings() {
    envForm.post(`/environments/${props.environment.id}/settings`);
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
        const response = await fetch(`/environments/${props.environment.id}/config`, {
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
            tld.value = editTld.value.trim() || 'test';
        } else {
            alert('Failed to save config: ' + (result.error || 'Unknown error'));
        }
    } catch {
        alert('Failed to save config');
    } finally {
        configSaving.value = false;
    }
}

function confirmDelete() {
    showDeleteConfirm.value = true;
    deleteConfirmName.value = '';
}

function cancelDelete() {
    showDeleteConfirm.value = false;
    deleteConfirmName.value = '';
}

function deleteEnvironment() {
    if (deleteConfirmName.value !== props.environment.name) {
        return;
    }

    router.delete(`/environments/${props.environment.id}`, {
        onSuccess: () => {
            // Will redirect to dashboard which will then redirect appropriately
        },
    });
}

onMounted(() => {
    loadConfig();
});
</script>

<template>
    <Head :title="`Settings - ${environment.name}`" />

    <div>
        <div class="mb-8">
            <Heading title="Settings" />
            <p class="text-zinc-400 mt-1">
                Configure {{ environment.name }}
            </p>
        </div>

        <form @submit.prevent="saveEnvSettings">
            <!-- Environment Name -->
            <div class="grid grid-cols-2 gap-8 py-6">
                <div>
                    <h3 class="text-sm font-medium text-white">Environment Name</h3>
                    <p class="text-sm text-zinc-500 mt-1">Display name for this environment.</p>
                </div>
                <div>
                    <input
                        v-model="envForm.name"
                        type="text"
                        id="name"
                        class="w-full"
                    />
                    <p v-if="envForm.errors.name" class="mt-2 text-sm text-red-400">{{ envForm.errors.name }}</p>
                </div>
            </div>

            <hr class="border-zinc-800" />

            <!-- SSH Connection (remote only) -->
            <template v-if="!environment.is_local">
                <div class="grid grid-cols-2 gap-8 py-6">
                    <div>
                        <h3 class="text-sm font-medium text-white">SSH Connection</h3>
                        <p class="text-sm text-zinc-500 mt-1">Host, user, and port for SSH access.</p>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label for="host" class="block text-sm font-medium text-zinc-400 mb-1">Host</label>
                            <input
                                v-model="envForm.host"
                                type="text"
                                id="host"
                                class="w-full font-mono"
                            />
                            <p v-if="envForm.errors.host" class="mt-1 text-sm text-red-400">{{ envForm.errors.host }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="user" class="block text-sm font-medium text-zinc-400 mb-1">User</label>
                                <input
                                    v-model="envForm.user"
                                    type="text"
                                    id="user"
                                    class="w-full font-mono"
                                />
                                <p v-if="envForm.errors.user" class="mt-1 text-sm text-red-400">{{ envForm.errors.user }}</p>
                            </div>
                            <div>
                                <label for="port" class="block text-sm font-medium text-zinc-400 mb-1">Port</label>
                                <input
                                    v-model="envForm.port"
                                    type="number"
                                    id="port"
                                    class="w-full font-mono"
                                />
                                <p v-if="envForm.errors.port" class="mt-1 text-sm text-red-400">{{ envForm.errors.port }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="border-zinc-800" />
            </template>

            <!-- Save Environment Button -->
            <div class="flex justify-end py-6">
                <button
                    type="submit"
                    :disabled="envForm.processing"
                    class="btn btn-secondary disabled:opacity-50"
                >
                    {{ envForm.processing ? 'Saving...' : 'Save Environment' }}
                </button>
            </div>
        </form>

        <hr class="border-zinc-800" />

        <!-- Launchpad Configuration Section -->
        <div class="py-6">
            <h2 class="text-lg font-medium text-white mb-6">Launchpad Configuration</h2>

            <div v-if="configLoading" class="text-zinc-500 text-sm">
                <Loader2 class="w-4 h-4 inline animate-spin mr-2" />
                Loading configuration...
            </div>

            <template v-else>
                <!-- Project Paths -->
                <div class="grid grid-cols-2 gap-8 py-6">
                    <div>
                        <h3 class="text-sm font-medium text-white">Project Paths</h3>
                        <p class="text-sm text-zinc-500 mt-1">Directories where your projects are located.</p>
                    </div>
                    <div>
                        <div class="space-y-2">
                            <div v-for="(path, index) in editPaths" :key="index" class="flex items-center gap-2">
                                <input
                                    v-model="editPaths[index]"
                                    type="text"
                                    placeholder="/home/user/projects"
                                    class="flex-1 font-mono"
                                />
                                <button
                                    @click="removePath(index)"
                                    type="button"
                                    class="text-zinc-500 hover:text-red-400 p-2 transition-colors"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </div>
                        <button
                            @click="addPath"
                            type="button"
                            class="mt-2 text-sm text-zinc-400 hover:text-white transition-colors flex items-center gap-1"
                        >
                            <Plus class="w-4 h-4" />
                            Add path
                        </button>
                    </div>
                </div>

                <hr class="border-zinc-800" />

                <!-- TLD -->
                <div class="grid grid-cols-2 gap-8 py-6">
                    <div>
                        <h3 class="text-sm font-medium text-white">TLD</h3>
                        <p class="text-sm text-zinc-500 mt-1">Top-level domain for local sites. Sites will be accessible at sitename.{{ editTld || 'test' }}</p>
                    </div>
                    <div>
                        <input
                            v-model="editTld"
                            type="text"
                            id="config-tld"
                            placeholder="test"
                            class="w-full font-mono"
                        />
                    </div>
                </div>

                <hr class="border-zinc-800" />

                <!-- Default PHP Version -->
                <div class="grid grid-cols-2 gap-8 py-6">
                    <div>
                        <h3 class="text-sm font-medium text-white">Default PHP Version</h3>
                        <p class="text-sm text-zinc-500 mt-1">PHP version used for new sites.</p>
                    </div>
                    <div>
                        <select v-model="editPhpVersion" id="config-php" class="w-full">
                            <option
                                v-for="version in availablePhpVersions"
                                :key="version"
                                :value="version"
                            >
                                PHP {{ version }}
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Save Config Button -->
                <div class="flex justify-end py-6">
                    <button
                        @click="saveConfig"
                        type="button"
                        :disabled="configSaving"
                        class="btn btn-secondary disabled:opacity-50"
                    >
                        {{ configSaving ? 'Saving...' : 'Save Configuration' }}
                    </button>
                </div>
            </template>
        </div>

        <hr class="border-zinc-800" />

        <!-- Orchestrator -->
        <div class="grid grid-cols-2 gap-8 py-6">
            <div>
                <div class="flex items-center gap-2">
                    <Workflow class="w-5 h-5" :class="isOrchestratorEnabled ? 'text-lime-400' : 'text-zinc-400'" />
                    <h3 class="text-sm font-medium text-white">Orchestrator</h3>
                    <span
                        v-if="isOrchestratorEnabled"
                        class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-lime-500/10 text-lime-400"
                    >
                        <Check class="w-3 h-3" />
                        Connected
                    </span>
                </div>
                <p class="text-sm text-zinc-500 mt-1">
                    {{ isOrchestratorEnabled ? environment.orchestrator_url : 'AI-assisted project management with MCP integration.' }}
                </p>
                <!-- Progress/Status -->
                <div v-if="!isOrchestratorEnabled && (orchestratorBusy || orchestratorError)" class="mt-3">
                    <div v-if="orchestratorStatus" class="flex items-center gap-2">
                        <Loader2 class="w-4 h-4 animate-spin text-lime-400" />
                        <span class="text-sm text-zinc-300">{{ orchestratorStatus }}</span>
                    </div>
                    <div v-if="orchestratorError" class="flex items-center gap-2 text-sm text-red-400">
                        <AlertCircle class="w-4 h-4" />
                        {{ orchestratorError }}
                    </div>
                </div>
            </div>
            <div class="flex items-start">
                <button
                    v-if="isOrchestratorEnabled"
                    @click="disableOrchestrator"
                    type="button"
                    class="btn btn-plain text-red-400 hover:text-red-300"
                >
                    Disable
                </button>
                <button
                    v-else-if="!orchestratorBusy"
                    @click="enableOrchestrator"
                    type="button"
                    class="btn btn-secondary"
                >
                    Enable
                </button>
            </div>
        </div>

        <hr class="border-zinc-800" />

        <!-- Health Check / Doctor -->
        <div class="py-6">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-2">
                    <Stethoscope class="w-5 h-5 text-zinc-400" />
                    <h2 class="text-lg font-medium text-white">Health Check</h2>
                    <span
                        v-if="doctorResult"
                        :class="[
                            'text-xs px-2 py-0.5 rounded-full',
                            doctorResult.status === 'healthy' ? 'bg-lime-500/10 text-lime-400' :
                            doctorResult.status === 'degraded' ? 'bg-amber-500/10 text-amber-400' :
                            'bg-red-500/10 text-red-400'
                        ]"
                    >
                        {{ doctorResult.status === 'healthy' ? 'All Passed' :
                           doctorResult.status === 'degraded' ? 'Warnings' : 'Issues Found' }}
                    </span>
                </div>
                <button
                    @click="runDoctor"
                    :disabled="doctorRunning"
                    class="btn btn-outline flex items-center gap-2"
                >
                    <RefreshCw v-if="doctorRunning" class="w-4 h-4 animate-spin" />
                    <Stethoscope v-else class="w-4 h-4" />
                    {{ doctorRunning ? 'Running...' : 'Run Diagnostics' }}
                </button>
            </div>

            <!-- Error State -->
            <div v-if="doctorError" class="p-4 bg-red-900/20 border border-red-800 rounded-lg mb-4">
                <div class="flex items-center gap-2 text-red-400">
                    <XCircle class="w-5 h-5" />
                    <span>{{ doctorError }}</span>
                </div>
            </div>

            <!-- Results -->
            <div v-if="doctorResult" class="space-y-2">
                <!-- Summary -->
                <div class="flex items-center gap-4 mb-4 text-sm">
                    <span class="text-lime-400">{{ doctorResult.summary.passed }} passed</span>
                    <span v-if="doctorResult.summary.warnings > 0" class="text-amber-400">
                        {{ doctorResult.summary.warnings }} warnings
                    </span>
                    <span v-if="doctorResult.summary.errors > 0" class="text-red-400">
                        {{ doctorResult.summary.errors }} errors
                    </span>
                </div>

                <!-- Individual Checks -->
                <div
                    v-for="(check, key) in doctorResult.checks"
                    :key="key"
                    class="border border-zinc-700/50 rounded-lg overflow-hidden"
                >
                    <button
                        @click="toggleCheckExpanded(key as string)"
                        class="w-full flex items-center justify-between p-3 bg-zinc-800/30 hover:bg-zinc-700/30 transition-colors text-left"
                    >
                        <div class="flex items-center gap-3">
                            <!-- Status Icon -->
                            <CheckCircle2
                                v-if="check.status === 'ok'"
                                class="w-5 h-5 text-lime-400"
                            />
                            <AlertTriangleIcon
                                v-else-if="check.status === 'warning'"
                                class="w-5 h-5 text-amber-400"
                            />
                            <XCircle
                                v-else
                                class="w-5 h-5 text-red-400"
                            />

                            <!-- Check Name & Message -->
                            <div>
                                <span class="text-white font-medium">
                                    {{ checkLabels[key as string] || key }}
                                </span>
                                <p class="text-sm text-zinc-400 mt-0.5">{{ check.message }}</p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <!-- Fix Button -->
                            <button
                                v-if="check.status === 'error' && check.details?.can_fix"
                                @click.stop="fixIssue(key as string)"
                                :disabled="fixingChecks.has(key as string)"
                                class="btn btn-secondary py-1 px-2 text-xs"
                            >
                                <Loader2 v-if="fixingChecks.has(key as string)" class="w-3 h-3 animate-spin mr-1" />
                                {{ fixingChecks.has(key as string) ? 'Fixing...' : 'Fix' }}
                            </button>

                            <!-- Expand Arrow -->
                            <ChevronDown
                                v-if="check.details && Object.keys(check.details).length > 0"
                                :class="[
                                    'w-4 h-4 text-zinc-500 transition-transform',
                                    expandedChecks.has(key as string) ? 'rotate-180' : ''
                                ]"
                            />
                        </div>
                    </button>

                    <!-- Details (expanded) -->
                    <div
                        v-if="expandedChecks.has(key as string) && check.details && Object.keys(check.details).filter(k => k !== 'can_fix').length > 0"
                        class="px-4 py-3 bg-zinc-900/50 border-t border-zinc-700/50"
                    >
                        <dl class="space-y-2 text-sm">
                            <div
                                v-for="(value, detailKey) in check.details"
                                :key="detailKey"
                                v-show="detailKey !== 'can_fix'"
                                class="flex"
                            >
                                <dt class="text-zinc-500 w-32 flex-shrink-0">{{ detailKey }}</dt>
                                <dd class="text-zinc-300 font-mono break-all">
                                    <template v-if="Array.isArray(value)">
                                        <span v-for="(item, i) in value" :key="i" class="block">{{ item }}</span>
                                    </template>
                                    <template v-else-if="typeof value === 'object' && value !== null">
                                        <pre class="text-xs">{{ JSON.stringify(value, null, 2) }}</pre>
                                    </template>
                                    <template v-else>{{ value }}</template>
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else-if="!doctorRunning && !doctorError" class="text-center py-8 text-zinc-500">
                <Stethoscope class="w-12 h-12 mx-auto mb-3 opacity-50" />
                <p>Run diagnostics to check the health of this environment</p>
            </div>
        </div>

        <hr class="border-zinc-800" />

        <!-- Danger Zone -->
        <div class="py-6">
            <h2 class="text-lg font-medium text-red-400 mb-6">Danger Zone</h2>

            <div class="grid grid-cols-2 gap-8">
                <div>
                    <h3 class="text-sm font-medium text-white">Delete Environment</h3>
                    <p class="text-sm text-zinc-500 mt-1">Permanently delete this environment. This action cannot be undone.</p>
                </div>
                <div>
                    <div v-if="!showDeleteConfirm">
                        <button
                            @click="confirmDelete"
                            type="button"
                            class="btn bg-red-900/50 text-red-400 hover:bg-red-900 border border-red-800"
                        >
                            Delete Environment
                        </button>
                    </div>

                    <div v-else class="space-y-4">
                        <div class="flex items-start gap-3 p-3 bg-red-900/20 rounded-lg">
                            <AlertTriangle class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
                            <div>
                                <p class="text-sm text-red-300 font-medium">Are you sure?</p>
                                <p class="text-sm text-red-400/80">
                                    Type <strong class="text-red-300">{{ environment.name }}</strong> to confirm.
                                </p>
                            </div>
                        </div>
                        <input
                            v-model="deleteConfirmName"
                            type="text"
                            placeholder="Type environment name"
                            class="w-full"
                        />
                        <div class="flex gap-3">
                            <button
                                @click="deleteEnvironment"
                                type="button"
                                :disabled="deleteConfirmName !== environment.name"
                                class="btn bg-red-600 text-white hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                Delete
                            </button>
                            <button
                                @click="cancelDelete"
                                type="button"
                                class="btn btn-plain"
                            >
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
