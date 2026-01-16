<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import axios from 'axios';
import api from '@/lib/axios';
import Heading from '@/components/Heading.vue';
import { ExternalLink, Code, Loader2, FolderOpen, Globe, Plus, AlertCircle, Trash2, Check, RefreshCw, Package, Terminal } from 'lucide-vue-next';
import { useProjectProvisioning, type ProvisioningProject, type ProvisionStatus, type DeletionStatus } from '@/composables/useProjectProvisioning';
import Modal from '@/components/Modal.vue';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
    orchestrator_url: string | null;
}

interface Editor {
    scheme: string;
    name: string;
}

interface Project {
    name: string;
    display_name?: string;
    github_repo?: string | null;
    project_type?: string;
    path: string;
    has_public_folder: boolean;
    php_version?: string;
    domain?: string;
    site_url?: string;
}

const props = defineProps<{
    environment: Environment;
    editor: Editor;
    remoteApiUrl: string | null; // Direct API URL for remote environments (bypasses NativePHP)
}>();

// Helper to get the API URL - uses remote API directly when available, falls back to NativePHP
const getApiUrl = (path: string) => {
    if (props.remoteApiUrl) {
        return `${props.remoteApiUrl}${path}`;
    }
    return `/api/environments/${props.environment.id}${path}`;
};

const page = usePage();

const projects = ref<Project[]>([]);
const loading = ref(true);
const tld = ref('test');
const defaultPhpVersion = ref('8.4');
const availablePhpVersions = ref<string[]>(['8.3', '8.4', '8.5']);
const changingPhpFor = ref<string | null>(null);

// Combine real projects with provisioning projects that might not be in the list yet
const allProjects = computed(() => {
    const projectMap = new Map(projects.value.map(p => [p.name, p]));

    // Add placeholder entries for provisioning projects not in the list
    for (const [slug, provProject] of provisioningProjects.value) {
        if (!projectMap.has(slug)) {
            projectMap.set(slug, {
                name: slug,
                path: `~/projects/${slug}`,
                has_public_folder: false,
                php_version: defaultPhpVersion.value,
            });
        }
    }

    // Sort alphabetically by name (case-insensitive)
    return Array.from(projectMap.values()).sort((a, b) =>
        a.name.toLowerCase().localeCompare(b.name.toLowerCase())
    );
});

// Initialize provisioning composable
const {
    provisioningProjects,
    deletingProjects,
    isConnected,
    connectionError,
    projectReadyCount,
    projectDeletedCount,
    connect,
    disconnect,
    trackProject,
    getProjectStatus,
    trackDeletion,
    getDeletionStatus,
    markDeletionComplete,
    markDeletionFailed,
    clearDeletion,
} = useProjectProvisioning(props.environment.id);

// Get provisioning slug from flash data or URL query param
const provisioningSlug = computed(() => {
    // First check flash data (from Inertia form submission)
    const flash = page.props.flash as Record<string, string> | undefined;
    if (flash?.provisioning) {
        return flash.provisioning;
    }
    // Also check URL query params (from direct API submission + router.visit)
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('provisioning');
});

// Status display helpers
const statusLabels: Record<ProvisionStatus, string> = {
    provisioning: 'Initializing...',
    creating_repo: 'Creating repository...',
    cloning: 'Cloning...',
    setting_up: 'Setting up...',
    installing_composer: 'Installing Composer...',
    installing_npm: 'Installing dependencies...',
    building: 'Building assets...',
    finalizing: 'Finalizing...',
    ready: 'Ready',
    failed: 'Failed',
};

const deletionStatusLabels: Record<DeletionStatus, string> = {
    deleting: 'Deleting...',
    removing_orchestrator: 'Removing from Orchestrator...',
    removing_vibekanban: 'Removing from VibeKanban...',
    removing_linear: 'Removing from Linear...',
    removing_files: 'Removing files...',
    deleted: 'Deleted',
    delete_failed: 'Delete failed',
};

function getProjectSlug(projectName: string): string {
    return projectName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function isProjectDeleting(projectName: string): boolean {
    const slug = getProjectSlug(projectName);
    const status = getDeletionStatus(slug);
    if (!status) return false;
    // Include 'deleted' so the row stays dimmed until it's removed from the list
    return status.status !== 'delete_failed';
}

function getProjectDeletionStatus(projectName: string): DeletionStatus | null {
    const slug = getProjectSlug(projectName);
    const status = getDeletionStatus(slug);
    return status?.status ?? null;
}

function getProvisioningStatus(projectName: string): ProvisioningProject | undefined {
    // Check by slug (kebab-case of name)
    const slug = projectName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    return getProjectStatus(slug);
}

function isProjectProvisioning(projectName: string): boolean {
    const status = getProvisioningStatus(projectName);
    if (!status) return false;
    return status.status !== 'ready' && status.status !== 'failed';
}

function getProjectProvisionStatus(projectName: string): ProvisionStatus | null {
    const status = getProvisioningStatus(projectName);
    // Return null for 'ready' so it's treated as done (no status shown)
    if (status?.status === 'ready') return null;
    return status?.status ?? null;
}

// Handle API errors with user-friendly notifications
function handleApiError(error: string | undefined, context: string) {
    const errorMsg = error || 'Unknown error';
    if (errorMsg.includes('Connection error')) {
        toast.error('Connection Error', {
            description: 'Could not connect to the environment. Please check if Orbit is running.',
        });
    } else {
        toast.error(`Failed to ${context}`, {
            description: errorMsg,
        });
    }
}

async function loadProjects(silent = false) {
    if (!silent) {
        loading.value = true;
    }
    try {
        const { data: result } = await api.get(getApiUrl('/projects'));

        if (result.success && result.data) {
            projects.value = result.data.projects || [];
            tld.value = result.data.tld || 'test';
            defaultPhpVersion.value = result.data.default_php_version || '8.4';
            if (result.data.available_php_versions?.length) {
                availablePhpVersions.value = result.data.available_php_versions;
            }
        } else if (!result.success && !silent) {
            handleApiError(result.error, 'load projects');
        }
    } catch (error) {
        if (axios.isCancel(error)) return;
        console.error('Failed to load projects:', error);
        // Error toast handled by axios interceptor for non-silent loads
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
}

async function openSite(domain: string) {
    const url = `https://${domain}`;
    try {
        await api.post('/open-external', { url });
    } catch {
        // Silent fail for opening URLs
    }
}

async function openInEditor(path: string) {
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

async function changePhpVersion(project: Project, version: string) {
    if (!project.domain) return;

    changingPhpFor.value = project.name;
    try {
        const { data: result } = await api.post(getApiUrl(`/php/${project.name}`), {
            version: version,
        });

        if (result.success) {
            toast.success(`PHP version changed to ${version}`);
            // Refresh projects to get updated PHP version
            await loadProjects(true);
        } else {
            handleApiError(result.error, 'change PHP version');
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        changingPhpFor.value = null;
    }
}

// Delete project state
const showDeleteModal = ref(false);
const projectToDelete = ref<Project | null>(null);
const deleting = ref(false);
const deleteError = ref<string | null>(null);

function confirmDelete(project: Project) {
    projectToDelete.value = project;
    deleteError.value = null;
    showDeleteModal.value = true;
}

async function deleteProject() {
    if (!projectToDelete.value) return;

    const projectName = projectToDelete.value.name;
    const slug = getProjectSlug(projectName);

    // Warn if WebSocket is not connected
    if (!isConnected.value) {
        toast.warning('Real-time updates unavailable', {
            description: 'Deletion will proceed but you may need to refresh the page to see updated status.',
        });
    }

    // Close modal immediately and start tracking deletion
    showDeleteModal.value = false;
    trackDeletion(slug);
    projectToDelete.value = null;
    deleting.value = false;
    deleteError.value = null;

    try {
        const { data: result } = await api.delete(getApiUrl(`/projects/${slug}`));

        if (result.success) {
            // Async deletion - WebSocket will broadcast 'deleted' when done
            // The deletingProjects watcher will reload the list
            if (!isConnected.value) {
                // Without WebSocket, reload after a delay as fallback
                setTimeout(() => loadProjects(true), 3000);
            }
        } else {
            markDeletionFailed(slug, result.error || 'Failed to delete project');
        }
    } catch (error) {
        console.error('Failed to delete project:', error);
        markDeletionFailed(slug, 'An error occurred while deleting the project');
    }
}

// Rebuild project state
const rebuildingProject = ref<string | null>(null);

async function rebuildProject(project: Project) {
    rebuildingProject.value = project.name;
    const slug = getProjectSlug(project.name);
    try {
        const { data: result } = await api.post(getApiUrl(`/projects/${slug}/rebuild`), {});

        if (result.success) {
            toast.success(`Project "${project.name}" rebuilt successfully`);
            // Refresh projects to get updated status
            await loadProjects(true);
        } else {
            handleApiError(result.error, 'rebuild project');
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        rebuildingProject.value = null;
    }
}

// Watch for project ready events and reload the list
// Using counter is more reliable than watching Map changes
watch(projectReadyCount, () => {
    // Debounce reload to prevent multiple refreshes
    setTimeout(() => {
        loadProjects(true);  // Silent refresh - no spinner
    }, 500);
});

// Watch for project deleted events and reload the list
watch(projectDeletedCount, () => {
    // Debounce reload to prevent multiple refreshes
    setTimeout(() => {
        loadProjects(true);  // Silent refresh - no spinner
    }, 500);
});

onMounted(() => {
    // Track project from flash data IMMEDIATELY (before loading projects or connecting)
    // This ensures the provisioning state shows instantly
    if (provisioningSlug.value) {
        trackProject(provisioningSlug.value);
    }

    // Load projects list (non-blocking - page renders immediately with loading state)
    loadProjects();

    // Connect to WebSocket for real-time provisioning updates (non-blocking)
    connect();

    // Re-track after connect to ensure WebSocket subscription is set up
    if (provisioningSlug.value) {
        trackProject(provisioningSlug.value);
        // No polling fallback - rely solely on WebSocket updates
        // If WebSocket fails, user will see stuck "provisioning" state
    }
});

</script>

<template>
    <Head :title="`Projects - ${environment.name}`" />

    <div>
        <div class="mb-8 flex items-start justify-between">
            <div>
                <Heading title="Projects" />
                <p class="text-zinc-400 mt-1">
                    Projects in {{ environment.name }}
                </p>
            </div>
            <Link
                :href="`/environments/${environment.id}/projects/create`"
                class="btn btn-secondary"
            >
                <Plus class="w-4 h-4" />
                New Project
            </Link>
        </div>

        <!-- WebSocket Connection Warning -->
        <div v-if="connectionError" class="mb-6 p-4 bg-amber-400/10 border border-amber-400/20 rounded-lg flex items-start gap-3">
            <AlertCircle class="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" />
            <div>
                <p class="text-amber-400 font-medium">Real-time updates unavailable</p>
                <p class="text-zinc-400 text-sm mt-1">
                    Could not connect to WebSocket: {{ connectionError }}.
                    Status updates for provisioning and deletion will not appear automatically.
                </p>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="border border-zinc-800 rounded-lg p-8 text-center">
            <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-3" />
            <p class="text-zinc-500">Loading projects...</p>
        </div>

        <!-- Empty State -->
        <div v-else-if="allProjects.length === 0" class="border border-zinc-800 rounded-lg p-8 text-center">
            <FolderOpen class="w-12 h-12 mx-auto text-zinc-600 mb-3" />
            <h3 class="text-lg font-medium text-white mb-2">No projects found</h3>
            <p class="text-zinc-400">
                Add project directories in the environment configuration.
            </p>
        </div>

        <!-- Projects Table -->
        <div v-else class="border border-zinc-800 rounded-lg overflow-hidden">
            <table class="table-catalyst w-full">
                <thead>
                    <tr class="border-b border-zinc-800">
                        <th class="px-6">Project</th>
                        <th class="px-6">Status</th>
                        <th class="px-6">PHP</th>
                        <th class="px-6 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800/50">
                    <tr
                        v-for="project in allProjects"
                        :key="project.name"
                        :class="{
                            'opacity-50': isProjectDeleting(project.name),
                            'bg-zinc-900/50': isProjectProvisioning(project.name),
                            'bg-lime-900/20': getProjectDeletionStatus(project.name) === 'deleted',
                            'bg-red-900/20': isProjectDeleting(project.name) && getProjectDeletionStatus(project.name) !== 'deleted',
                        }"
                    >
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <Check
                                    v-if="getProjectDeletionStatus(project.name) === 'deleted'"
                                    class="w-4 h-4 text-lime-400"
                                />
                                <Loader2
                                    v-else-if="isProjectDeleting(project.name)"
                                    class="w-4 h-4 text-red-400 animate-spin"
                                />
                                <Loader2
                                    v-else-if="isProjectProvisioning(project.name)"
                                    class="w-4 h-4 text-amber-400 animate-spin"
                                />
                                <AlertCircle
                                    v-else-if="getProjectProvisionStatus(project.name) === 'failed' || getProjectDeletionStatus(project.name) === 'delete_failed'"
                                    class="w-4 h-4 text-red-400"
                                />
                                <Package
                                    v-else-if="project.project_type === 'laravel-package'"
                                    class="w-4 h-4 text-purple-400"
                                />
                                <Terminal
                                    v-else-if="project.project_type === 'cli'"
                                    class="w-4 h-4 text-amber-400"
                                />
                                <Globe
                                    v-else-if="project.has_public_folder"
                                    class="w-4 h-4 text-lime-400"
                                />
                                <FolderOpen
                                    v-else
                                    class="w-4 h-4 text-zinc-400"
                                />
                                <div class="flex flex-col">
                                    <span class="font-medium text-white">{{ project.display_name || project.name }}</span>
                                    <span
                                        v-if="project.github_repo && !isProjectProvisioning(project.name) && !isProjectDeleting(project.name)"
                                        class="text-zinc-400 text-xs"
                                    >
                                        {{ project.github_repo }}
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <!-- Deletion status -->
                            <div v-if="getProjectDeletionStatus(project.name)" class="flex items-center gap-2">
                                <span
                                    v-if="getProjectDeletionStatus(project.name) === 'deleted'"
                                    class="text-xs px-2 py-0.5 rounded-full bg-lime-400/10 text-lime-400 border border-lime-400/20"
                                >
                                    Deleted
                                </span>
                                <span
                                    v-else-if="getProjectDeletionStatus(project.name) === 'delete_failed'"
                                    class="text-xs px-2 py-0.5 rounded-full bg-red-400/10 text-red-400 border border-red-400/20"
                                >
                                    Delete failed
                                </span>
                                <span
                                    v-else-if="isProjectDeleting(project.name)"
                                    class="text-xs px-2 py-0.5 rounded-full bg-red-400/10 text-red-400 border border-red-400/20"
                                >
                                    {{ deletionStatusLabels[getProjectDeletionStatus(project.name)!] }}
                                </span>
                            </div>
                            <!-- Provisioning status -->
                            <div v-else-if="getProjectProvisionStatus(project.name)" class="flex items-center gap-2">
                                <span
                                    v-if="isProjectProvisioning(project.name)"
                                    class="text-xs px-2 py-0.5 rounded-full bg-amber-400/10 text-amber-400 border border-amber-400/20"
                                >
                                    {{ statusLabels[getProjectProvisionStatus(project.name)!] }}
                                </span>
                                <span
                                    v-else-if="getProjectProvisionStatus(project.name) === 'failed'"
                                    class="text-xs px-2 py-0.5 rounded-full bg-red-400/10 text-red-400 border border-red-400/20"
                                >
                                    Failed
                                </span>
                            </div>
                            <span v-else class="text-xs text-zinc-500">—</span>
                        </td>
                        <td class="px-6 py-4">
                            <div v-if="isProjectProvisioning(project.name) || isProjectDeleting(project.name)" class="text-sm text-zinc-500 font-mono">
                                {{ project.php_version || defaultPhpVersion }}
                            </div>
                            <div v-else-if="changingPhpFor === project.name" class="flex items-center gap-2">
                                <Loader2 class="w-4 h-4 text-zinc-400 animate-spin" />
                                <span class="text-sm text-zinc-500">Changing...</span>
                            </div>
                            <select
                                v-else
                                :value="project.php_version || defaultPhpVersion"
                                @change="(e) => changePhpVersion(project, (e.target as HTMLSelectElement).value)"
                                class="text-xs py-1 pl-2 pr-7 font-mono bg-zinc-800 border-zinc-700 rounded"
                                :disabled="!project.has_public_folder"
                            >
                                <option
                                    v-for="version in availablePhpVersions"
                                    :key="version"
                                    :value="version"
                                >
                                    PHP {{ version }}
                                </option>
                            </select>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div v-if="getProjectDeletionStatus(project.name) === 'deleted'" class="text-xs text-lime-400">
                                Deleted
                            </div>
                            <div v-else-if="isProjectDeleting(project.name)" class="text-xs text-red-400">
                                Deleting...
                            </div>
                            <div v-else-if="isProjectProvisioning(project.name)" class="text-xs text-zinc-500">
                                Setting up...
                            </div>
                            <div v-else class="flex items-center justify-end gap-2">
                                <button
                                    v-if="project.has_public_folder && project.domain"
                                    @click="openSite(project.domain)"
                                    class="btn btn-secondary py-1 px-2 text-xs"
                                >
                                    <ExternalLink class="w-3.5 h-3.5" />
                                    Open
                                </button>
                                <button
                                    @click="openInEditor(project.path)"
                                    class="btn btn-outline py-1 px-2 text-xs"
                                >
                                    <Code class="w-3.5 h-3.5" />
                                    {{ editor.name }}
                                </button>
                                <button
                                    v-if="project.has_public_folder && !isProjectProvisioning(project.name) && !isProjectDeleting(project.name)"
                                    @click="rebuildProject(project)"
                                    class="btn btn-plain py-1 px-2 text-xs text-zinc-500 hover:text-amber-400"
                                    :disabled="rebuildingProject === project.name"
                                    title="Rebuild project (reinstall deps, build assets)"
                                >
                                    <Loader2 v-if="rebuildingProject === project.name" class="w-3.5 h-3.5 animate-spin" />
                                    <RefreshCw v-else class="w-3.5 h-3.5" />
                                </button>
                                <button
                                    @click="confirmDelete(project)"
                                    class="btn btn-plain py-1 px-2 text-xs text-zinc-500 hover:text-red-400"
                                    title="Delete project"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Legend -->
        <div class="mt-4 flex items-center gap-6 text-xs text-zinc-500">
            <div class="flex items-center gap-1.5">
                <Globe class="w-3.5 h-3.5 text-lime-400" />
                <span>Has public folder</span>
            </div>
            <div class="flex items-center gap-1.5">
                <FolderOpen class="w-3.5 h-3.5 text-zinc-500" />
                <span>No public folder</span>
            </div>
            <div class="flex items-center gap-1.5 ml-auto">
                <span
                    v-if="isConnected"
                    class="inline-flex items-center gap-1 text-lime-400"
                >
                    <span class="w-1.5 h-1.5 rounded-full bg-lime-400"></span>
                    Live updates
                </span>
                <span
                    v-else
                    class="inline-flex items-center gap-1 text-amber-400"
                >
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                    No live updates
                </span>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <Modal
            :show="showDeleteModal"
            title="Delete Project"
            @close="showDeleteModal = false"
        >
            <div class="p-6">
                <p class="text-zinc-300 mb-4">
                    Are you sure you want to delete <strong class="text-white">{{ projectToDelete?.name }}</strong>?
                </p>
                <p class="text-zinc-400 text-sm mb-4">
                    This will:
                </p>
                <ul class="text-zinc-400 text-sm mb-6 list-disc list-inside space-y-1">
                    <li>Remove the project directory from the server</li>
                    <li v-if="environment.orchestrator_url">Delete from Orchestrator</li>
                    <li v-if="environment.orchestrator_url">Delete from VibeKanban</li>
                    <li v-if="environment.orchestrator_url">Delete from Linear</li>
                </ul>
                <p class="text-red-400 text-sm mb-6">
                    This action cannot be undone.
                </p>

                <div v-if="deleteError" class="mb-4 p-3 bg-red-400/10 border border-red-400/20 rounded-lg">
                    <p class="text-red-400 text-sm">{{ deleteError }}</p>
                </div>

                <div class="flex justify-end gap-3">
                    <button
                        @click="showDeleteModal = false"
                        class="btn btn-outline"
                        :disabled="deleting"
                    >
                        Cancel
                    </button>
                    <button
                        @click="deleteProject"
                        class="btn bg-red-500 hover:bg-red-600 text-white"
                        :disabled="deleting"
                    >
                        <Loader2 v-if="deleting" class="w-4 h-4 animate-spin" />
                        <Trash2 v-else class="w-4 h-4" />
                        {{ deleting ? 'Deleting...' : 'Delete Project' }}
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
