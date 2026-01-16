<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import {
    Workflow,
    ExternalLink,
    Check,
    AlertCircle,
    Loader2,
    Download,
    RefreshCw,
    FolderSearch,
    FolderGit2,
    GitBranch,
    Clock,
} from 'lucide-vue-next';
import { Button, Badge } from '@hardimpactdev/craft-ui';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    port: number;
    is_local: boolean;
    orchestrator_url: string | null;
}

interface OrchestratorInstallation {
    path: string;
    url: string;
    configured: boolean;
}

interface OrchestratorProject {
    id: string;
    name: string;
    slug: string;
    github_url?: string;
    linear_team_id?: string;
    linear_team_name?: string;
    created_at: string;
    deployments_count?: number;
}

const props = defineProps<{
    environment: Environment;
}>();

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

const isEnabled = computed(() => !!props.environment.orchestrator_url);

// Projects state
const projects = ref<OrchestratorProject[]>([]);
const loadingProjects = ref(false);
const projectsError = ref<string | null>(null);

// Detection state
const detecting = ref(false);
const detectedInstallations = ref<OrchestratorInstallation[]>([]);
const detectionError = ref<string | null>(null);
const tld = ref('test');

// Installation state
const installing = ref(false);
const installProgress = ref<string | null>(null);
const installError = ref<string | null>(null);
const installSuccess = ref(false);

// Reconciliation state
const reconciling = ref(false);
const reconcileError = ref<string | null>(null);

async function detectOrchestrator() {
    detecting.value = true;
    detectionError.value = null;

    try {
        const response = await fetch(`/environments/${props.environment.id}/orchestrator/detect`);
        const result = await response.json();

        if (result.success) {
            detectedInstallations.value = result.installations || [];
            tld.value = result.tld || 'test';
        } else {
            detectionError.value = result.error || 'Detection failed';
        }
    } catch (error) {
        detectionError.value = 'Failed to detect orchestrator installations';
    } finally {
        detecting.value = false;
    }
}

async function installOrchestrator() {
    installing.value = true;
    installError.value = null;
    installProgress.value = 'Cloning orchestrator repository...';

    try {
        const response = await fetch(`/environments/${props.environment.id}/orchestrator/install`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/json',
            },
        });

        const result = await response.json();

        if (result.success) {
            installSuccess.value = true;
            installProgress.value = 'Installation complete!';
            // Reload page to show enabled state
            setTimeout(() => {
                router.reload();
            }, 1500);
        } else {
            installError.value = result.error || 'Installation failed';
            installProgress.value = null;
        }
    } catch (error) {
        installError.value = 'Failed to install orchestrator';
        installProgress.value = null;
    } finally {
        if (!installSuccess.value) {
            installing.value = false;
        }
    }
}

async function reconcileOrchestrator(installation: OrchestratorInstallation) {
    reconciling.value = true;
    reconcileError.value = null;

    try {
        const response = await fetch(
            `/environments/${props.environment.id}/orchestrator/reconcile`,
            {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ path: installation.path }),
            },
        );

        const result = await response.json();

        if (result.success) {
            // Reload page to show enabled state
            router.reload();
        } else {
            reconcileError.value = result.error || 'Reconciliation failed';
        }
    } catch (error) {
        reconcileError.value = 'Failed to reconcile orchestrator';
    } finally {
        reconciling.value = false;
    }
}

async function openOrchestrator() {
    if (props.environment.orchestrator_url) {
        try {
            await fetch('/open-external', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: props.environment.orchestrator_url }),
            });
        } catch (error) {
            console.error('Failed to open orchestrator:', error);
        }
    }
}

async function openProjectInOrchestrator(project: OrchestratorProject) {
    if (props.environment.orchestrator_url) {
        const url = `${props.environment.orchestrator_url.replace(/\/$/, '')}/projects/${project.slug}`;
        try {
            await fetch('/open-external', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Content-Type': 'application/json' },
                body: JSON.stringify({ url }),
            });
        } catch (error) {
            console.error('Failed to open project:', error);
        }
    }
}

async function loadProjects() {
    if (!props.environment.orchestrator_url) return;

    loadingProjects.value = true;
    projectsError.value = null;

    try {
        const response = await fetch(`/environments/${props.environment.id}/orchestrator/projects`);
        const result = await response.json();

        if (result.success) {
            projects.value = result.projects || [];
        } else {
            projectsError.value = result.error || 'Failed to load projects';
        }
    } catch (error) {
        projectsError.value = 'Could not connect to orchestrator';
    } finally {
        loadingProjects.value = false;
    }
}

function formatDate(dateString: string) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

onMounted(() => {
    if (isEnabled.value) {
        loadProjects();
    } else {
        detectOrchestrator();
    }
});
</script>

<template>
    <Head :title="`Orchestrator - ${environment.name}`" />

    <div>
        <div class="mb-8">
            <Heading title="Orchestrator" />
            <p class="text-zinc-400 mt-1">Manage projects and tasks with AI assistance</p>
        </div>

        <!-- Orchestrator Enabled -->
        <div v-if="isEnabled" class="space-y-6">
            <div class="border border-zinc-800 rounded-lg p-6">
                <div class="flex items-start justify-between">
                    <div class="flex items-start gap-4">
                        <div
                            class="w-10 h-10 rounded-lg bg-lime-500/10 flex items-center justify-center"
                        >
                            <Workflow class="w-5 h-5 text-lime-400" />
                        </div>
                        <div>
                            <h3 class="text-sm font-medium text-white flex items-center gap-2">
                                Orchestrator Connected
                                <span
                                    class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full bg-lime-500/10 text-lime-400"
                                >
                                    <Check class="w-3 h-3" />
                                    Active
                                </span>
                            </h3>
                            <p class="text-sm text-zinc-400 mt-1">
                                {{ environment.orchestrator_url }}
                            </p>
                        </div>
                    </div>
                    <Button
                        @click="openOrchestrator"
                        variant="secondary"
                    >
                        Open Orchestrator
                        <ExternalLink class="w-4 h-4" />
                    </Button>
                </div>
            </div>

            <!-- Projects -->
            <div class="border border-zinc-800 rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-zinc-800 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-white">Projects</h3>
                    <button
                        @click="loadProjects"
                        :disabled="loadingProjects"
                        class="text-zinc-400 hover:text-white transition-colors"
                    >
                        <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loadingProjects }" />
                    </button>
                </div>

                <!-- Loading -->
                <div v-if="loadingProjects" class="p-8 text-center">
                    <Loader2 class="w-6 h-6 mx-auto text-zinc-600 animate-spin mb-2" />
                    <p class="text-sm text-zinc-500">Loading projects...</p>
                </div>

                <!-- Error -->
                <div v-else-if="projectsError" class="p-6">
                    <div class="flex items-center gap-2 text-sm text-red-400">
                        <AlertCircle class="w-4 h-4" />
                        {{ projectsError }}
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else-if="projects.length === 0" class="p-8 text-center">
                    <FolderGit2 class="w-10 h-10 mx-auto text-zinc-600 mb-3" />
                    <p class="text-sm text-zinc-400">No projects configured yet</p>
                    <p class="text-xs text-zinc-500 mt-1">Create a project to get started</p>
                </div>

                <!-- Projects List -->
                <div v-else class="divide-y divide-zinc-800/50">
                    <div
                        v-for="project in projects"
                        :key="project.id"
                        class="px-6 py-4 hover:bg-zinc-800/30 transition-colors"
                    >
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div
                                    class="w-8 h-8 rounded bg-zinc-800 flex items-center justify-center"
                                >
                                    <FolderGit2 class="w-4 h-4 text-zinc-400" />
                                </div>
                                <div>
                                    <h4 class="text-sm font-medium text-white">
                                        {{ project.name }}
                                    </h4>
                                    <div class="flex items-center gap-3 mt-0.5">
                                        <span
                                            v-if="project.github_url"
                                            class="text-xs text-zinc-500 flex items-center gap-1"
                                        >
                                            <GitBranch class="w-3 h-3" />
                                            {{
                                                project.github_url.replace(
                                                    'https://github.com/',
                                                    '',
                                                )
                                            }}
                                        </span>
                                        <span
                                            v-if="project.linear_team_name"
                                            class="text-xs text-zinc-500"
                                        >
                                            {{ project.linear_team_name }}
                                        </span>
                                        <span class="text-xs text-zinc-600 flex items-center gap-1">
                                            <Clock class="w-3 h-3" />
                                            {{ formatDate(project.created_at) }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <Button
                                @click="openProjectInOrchestrator(project)"
                                variant="outline"
                                size="sm"
                            >
                                <ExternalLink class="w-3.5 h-3.5" />
                                View
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- MCP Info -->
            <div class="border border-zinc-800 rounded-lg p-6">
                <h3 class="text-sm font-medium text-white mb-4">MCP Integration</h3>
                <p class="text-sm text-zinc-400 mb-4">
                    The orchestrator's MCP service is available to orbit-cli for AI-assisted
                    operations.
                </p>
                <div class="bg-zinc-900 rounded-lg p-4 font-mono text-sm text-zinc-300">
                    <div class="text-zinc-500"># MCP endpoint</div>
                    <div>{{ environment.orchestrator_url?.replace(/\/$/, '') }}/mcp</div>
                </div>
            </div>
        </div>

        <!-- Orchestrator Not Enabled -->
        <div v-else class="space-y-6">
            <!-- Detecting -->
            <div v-if="detecting" class="border border-zinc-800 rounded-lg p-6">
                <div class="flex items-center gap-3 text-zinc-400">
                    <Loader2 class="w-5 h-5 animate-spin" />
                    <span>Scanning for existing orchestrator installations...</span>
                </div>
            </div>

            <!-- Existing Installation Found (Not Configured) -->
            <div v-else-if="detectedInstallations.length > 0" class="space-y-6">
                <div class="border border-yellow-500/20 bg-yellow-500/5 rounded-lg p-6">
                    <div class="flex items-start gap-4">
                        <div
                            class="w-10 h-10 rounded-lg bg-yellow-500/10 flex items-center justify-center"
                        >
                            <FolderSearch class="w-5 h-5 text-yellow-400" />
                        </div>
                        <div class="flex-1">
                            <h3 class="text-sm font-medium text-white">
                                Existing Installation Found
                            </h3>
                            <p class="text-sm text-zinc-400 mt-1">
                                An orchestrator installation was detected but is not linked to this
                                environment.
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 space-y-3">
                        <div
                            v-for="installation in detectedInstallations"
                            :key="installation.path"
                            class="bg-zinc-900 rounded-lg p-4"
                        >
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-mono text-zinc-300">
                                        {{ installation.path }}
                                    </p>
                                    <p class="text-xs text-zinc-500 mt-1">
                                        Will be available at:
                                        <span class="text-zinc-400">{{ installation.url }}</span>
                                    </p>
                                </div>
                                <Button
                                    @click="reconcileOrchestrator(installation)"
                                    :disabled="reconciling"
                                >
                                    <Loader2 v-if="reconciling" class="w-4 h-4 animate-spin" />
                                    <RefreshCw v-else class="w-4 h-4" />
                                    {{ reconciling ? 'Linking...' : 'Link & Configure' }}
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="reconcileError"
                        class="mt-4 flex items-center gap-2 text-sm text-red-400"
                    >
                        <AlertCircle class="w-4 h-4" />
                        {{ reconcileError }}
                    </div>
                </div>

                <!-- Still show install option -->
                <div class="text-center text-zinc-500 text-sm">or</div>
            </div>

            <!-- Install New Orchestrator -->
            <div class="border border-zinc-800 rounded-lg p-6">
                <div class="flex items-start gap-4 mb-6">
                    <div class="w-10 h-10 rounded-lg bg-zinc-800 flex items-center justify-center">
                        <Download class="w-5 h-5 text-zinc-400" />
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-white">Install Orchestrator</h3>
                        <p class="text-sm text-zinc-400 mt-1">
                            Install a new orchestrator instance in your first project path.
                        </p>
                    </div>
                </div>

                <!-- Installation Progress -->
                <div v-if="installing || installSuccess" class="space-y-4">
                    <div class="bg-zinc-900 rounded-lg p-4">
                        <div class="flex items-center gap-3">
                            <Loader2
                                v-if="!installSuccess"
                                class="w-5 h-5 animate-spin text-lime-400"
                            />
                            <Check v-else class="w-5 h-5 text-lime-400" />
                            <span
                                class="text-sm"
                                :class="installSuccess ? 'text-lime-400' : 'text-zinc-300'"
                            >
                                {{ installProgress }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Install Button -->
                <div v-else class="space-y-4">
                    <div class="bg-zinc-900 rounded-lg p-4">
                        <p class="text-sm text-zinc-400">The orchestrator will be installed at:</p>
                        <p class="text-sm font-mono text-zinc-300 mt-1">~/projects/orchestrator</p>
                        <p class="text-xs text-zinc-500 mt-2">
                            Available at:
                            <span class="text-zinc-400">https://orchestrator.{{ tld }}</span>
                        </p>
                    </div>

                    <div v-if="installError" class="flex items-center gap-2 text-sm text-red-400">
                        <AlertCircle class="w-4 h-4" />
                        {{ installError }}
                    </div>

                    <Button
                        @click="installOrchestrator"
                        :disabled="installing"
                    >
                        <Download class="w-4 h-4" />
                        Install Orchestrator
                    </Button>
                </div>
            </div>

            <!-- Info Box -->
            <div class="border border-zinc-800 rounded-lg p-6 bg-zinc-900/50">
                <h4 class="text-sm font-medium text-white mb-3">What is the Orchestrator?</h4>
                <ul class="text-sm text-zinc-400 space-y-2">
                    <li class="flex items-start gap-2">
                        <span class="text-zinc-600">-</span>
                        Centralized project and task management across environments
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-zinc-600">-</span>
                        AI-assisted operations via MCP (Model Context Protocol)
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-zinc-600">-</span>
                        Git worktree management and deployment tracking
                    </li>
                    <li class="flex items-start gap-2">
                        <span class="text-zinc-600">-</span>
                        Team collaboration with Linear integration
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
