<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import axios from 'axios';
import api from '@/lib/axios';
import Heading from '@/components/Heading.vue';
import {
    ExternalLink,
    Code,
    Loader2,
    FolderOpen,
    Globe,
    Plus,
    AlertCircle,
    Trash2,
    Check,
    RefreshCw,
    Package,
    Terminal,
    Clock3,
} from 'lucide-vue-next';
import {
    useProjectProvisioning,
    type ProvisioningProject,
    type ProvisionStatus,
    type DeletionStatus,
} from '@/composables/useProjectProvisioning';
import Modal from '@/components/Modal.vue';
import { Button, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell, Checkbox, Label } from '@hardimpactdev/craft-ui';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
}

interface Editor {
    scheme: string;
    name: string;
}

interface Project {
    id: number;
    name: string;
    display_name?: string;
    github_repo?: string | null;
    project_type?: string | null;
    path: string;
    has_public_folder: boolean;
    php_version?: string;
    domain?: string | null;
    url?: string | null;
    status?: ProvisionStatus | null;
    error_message?: string | null;
}

const props = defineProps<{
    environment: Environment;
    editor: Editor;
    remoteApiUrl: string | null; // Direct API URL for remote environments (bypasses NativePHP)
}>();

// Helper to get the API URL - uses remote API directly when available, falls back to local API
const getApiUrl = (path: string) => {
    if (props.remoteApiUrl) {
        return `${props.remoteApiUrl}${path}`;
    }
    return `/api${path}`;
};

const page = usePage();

const projects = ref<Project[]>([]);
const provisioningStatuses = [
    'queued',
    'creating_repo',
    'cloning',
    'setting_up',
    'installing_composer',
    'installing_npm',
    'building',
    'finalizing',
];
const loading = ref(true);
const tld = ref('test');
const defaultPhpVersion = ref('8.4');
const availablePhpVersions = ref<string[]>(['8.3', '8.4', '8.5']);
const changingPhpFor = ref<string | null>(null);

// Combine real sites with provisioning sites that might not be in the list yet
const allProjects = computed(() => {
    const siteMap = new Map(projects.value.map((p) => [p.name, p]));

    // Add placeholder entries for provisioning sites not in the list
    for (const [slug, provSite] of provisioningProjects.value) {
        if (!siteMap.has(slug)) {
            siteMap.set(slug, {
                id: 0,
                name: slug,
                path: `~/projects/${slug}`,
                has_public_folder: false,
                php_version: defaultPhpVersion.value,
                status: provSite.status,
            });
        }
    }

    // Sort alphabetically by name (case-insensitive), filtering out invalid entries
    return Array.from(siteMap.values())
        .filter((p) => p.name != null)
        .sort((a, b) => a.name.toLowerCase().localeCompare(b.name.toLowerCase()));
});

// Initialize provisioning composable
const {
    provisioningProjects,
    deletingProjects,
    isConnected,
    connectionError,
    projectReadyCount,
    projectDeletedCount,
    isConfigured: isProvisioningConfigured,
    trackProject,
    getProjectStatus,
    trackDeletion: trackProjectDeletion,
    getDeletionStatus: getProjectDeletionStatus,
    markDeletionComplete: markProjectDeletionComplete,
    markDeletionFailed: markProjectDeletionFailed,
    clearDeletion: clearProjectDeletion,
} = useProjectProvisioning();

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
    queued: 'Queued...',
    provisioning: 'Initializing...',
    validating_package: 'Validating package...',
    creating_project: 'Creating project...',
    forking: 'Forking repository...',
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
    removing_files: 'Removing files...',
    deleted: 'Deleted',
    delete_failed: 'Delete failed',
};

function getProjectSlug(siteName: string): string {
    return siteName
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/(^-|-$)/g, '');
}

function isProjectDeleting(siteName: string): boolean {
    const slug = getProjectSlug(siteName);
    const status = getProjectDeletionStatus(slug);
    if (!status) return false;
    // Include 'deleted' so the row stays dimmed until it's removed from the list
    return status.status !== 'delete_failed';
}

function getProjectDeletionStatusValue(siteName: string): DeletionStatus | null {
    const slug = getProjectSlug(siteName);
    const status = getProjectDeletionStatus(slug);
    return status?.status ?? null;
}

function getProjectProvisioningStatus(project: Project): ProvisioningProject | null {
    // Check WebSocket status FIRST - it has the most up-to-date status
    const slug = project.name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/(^-|-$)/g, '');
    const wsStatus = getProjectStatus(slug);

    if (wsStatus) {
        return wsStatus;
    }

    // Fall back to database status if no WebSocket status available
    if (project.status && provisioningStatuses.includes(project.status)) {
        return {
            slug: project.name,
            status: project.status,
            error: project.error_message ?? null,
            projectId: project.id,
        };
    }

    return null;
}

function isProjectProvisioning(project: Project): boolean {
    const status = getProjectProvisioningStatus(project);
    if (!status) return false;
    return status.status !== 'ready' && status.status !== 'failed';
}

function getProjectProvisionStatus(project: Project): ProvisionStatus | null {
    const status = getProjectProvisioningStatus(project);
    // Return null for 'ready' so it's treated as done (no status shown)
    if (status?.status === 'ready') return null;
    return status?.status ?? null;
}

// Handle API errors with user-friendly notifications
function handleApiError(error: string | undefined, context: string) {
    const errorMsg = error || 'Unknown error';
    if (errorMsg.includes('Connection error')) {
        toast.error('Connection Error', {
            description: 'Could not connect to the environment. Check if Orbit is running.',
        });
    } else {
        const title = context.charAt(0).toUpperCase() + context.slice(1) + ' Failed';
        toast.error(title, {
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

function openSite(domain: string) {
    const url = `https://${domain}`;
    window.open(url, '_blank');
}

function openInEditor(path: string) {
    let url;
    if (props.environment.is_local) {
        url = `${props.editor.scheme}://file${path}`;
    } else {
        const sshHost = `${props.environment.user}@${props.environment.host}`;
        url = `${props.editor.scheme}://vscode-remote/ssh-remote+${sshHost}${path}?windowId=_blank`;
    }
    window.open(url, '_blank');
}

async function changePhpVersion(project: Project, version: string) {
    if (!project.domain) return;

    changingPhpFor.value = project.name;
    try {
        const projectName = encodeURIComponent(project.name);
        const { data: result } = await api.post(`/projects/${projectName}/php`, {
            version: version,
        });

        if (result.success) {
            toast.success('PHP Version Changed', {
                description: `Now using PHP ${version} for this project.`,
            });
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
const deleteDatabase = ref(true);

function confirmDeleteProject(project: Project) {
    projectToDelete.value = project;
    deleteError.value = null;
    deleteDatabase.value = true; // Reset to default (checked)
    showDeleteModal.value = true;
}

async function deleteProject() {
    if (!projectToDelete.value) return;

    const siteName = projectToDelete.value.name;
    const slug = getProjectSlug(siteName);

    // Warn if WebSocket is not connected
    if (!isConnected.value) {
        toast.warning('Real-time updates unavailable', {
            description:
                'Deletion will proceed but you may need to refresh the page to see updated status.',
        });
    }

    // Close modal immediately and start tracking deletion
    showDeleteModal.value = false;
    trackProjectDeletion(slug);
    projectToDelete.value = null;
    deleting.value = false;
    deleteError.value = null;

    // Capture the deleteDatabase value before resetting state
    const keepDb = !deleteDatabase.value;

    try {
        // When remoteApiUrl is set, use the flat route /projects/{slug}
        const deleteUrl = props.remoteApiUrl
            ? `/projects/${slug}`
            : getApiUrl(`/projects/${slug}`);

        const { data: result } = await api.delete(deleteUrl, {
            params: { keep_db: keepDb ? '1' : '0' },
        });

        if (result.success) {
            // Mark deletion complete immediately - the API is synchronous
            markProjectDeletionComplete(slug);
            // Reload sites to update the list
            await loadProjects(true);
        } else {
            markProjectDeletionFailed(slug, result.error || 'Failed to delete project');
        }
    } catch (error) {
        console.error('Failed to delete project:', error);
        markProjectDeletionFailed(slug, 'An error occurred while deleting the project');
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
            toast.success('Project Rebuilt', {
                description: `"${project.name}" has been rebuilt successfully.`,
            });
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

// Watch for site ready events - show toast and reload the list
watch(projectReadyCount, () => {
    // Find the site that just became ready
    for (const [slug, site] of provisioningProjects.value) {
        if (site.status === 'ready') {
            toast.success('Project Created', {
                description: `"${slug}" has been created successfully.`,
            });
            break;
        }
    }
    // Debounce reload to prevent multiple refreshes
    setTimeout(() => {
        loadProjects(true); // Silent refresh - no spinner
    }, 500);
});

// Watch for site deleted events - show toast and reload the list
watch(projectDeletedCount, () => {
    // Find the site that was just deleted
    for (const [slug, site] of deletingProjects.value) {
        if (site.status === 'deleted') {
            toast.success('Project Deleted', {
                description: `"${slug}" has been removed.`,
            });
            break;
        }
    }
    // Debounce reload to prevent multiple refreshes
    setTimeout(() => {
        loadProjects(true); // Silent refresh - no spinner
    }, 500);
});

// Watch for provisioning/deletion failures
watch(
    () => [...provisioningProjects.value.values()],
    (newSites, oldSites) => {
        for (const site of newSites) {
            const oldSite = oldSites?.find((p) => p.slug === site.slug);
            if (site.status === 'failed' && oldSite?.status !== 'failed') {
                toast.error(`Failed to create project "${site.slug}"`, {
                    description: site.error || 'Unknown error occurred',
                });
            }
        }
    },
    { deep: true },
);

watch(
    () => [...deletingProjects.value.values()],
    (newSites, oldSites) => {
        for (const site of newSites) {
            const oldSite = oldSites?.find((p) => p.slug === site.slug);
            if (site.status === 'delete_failed' && oldSite?.status !== 'delete_failed') {
                toast.error(`Failed to delete project "${site.slug}"`, {
                    description: site.error || 'Unknown error occurred',
                });
            }
        }
    },
    { deep: true },
);

onMounted(() => {
    // Track site from flash data IMMEDIATELY (before loading sites or connecting)
    // This ensures the provisioning state shows instantly
    if (provisioningSlug.value) {
        trackProject(provisioningSlug.value);
    }

    // Load sites list (non-blocking - page renders immediately with loading state)
    loadProjects();

    // No explicit connect needed; Echo is configured globally.
});
</script>

<template>
    <Head :title="`Projects - ${environment.name}`" />

    <div>
        <!-- Header -->
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">Projects</h1>
                <p class="text-sm text-zinc-500 mt-1">Projects in {{ environment.name }}</p>
            </div>
            <div class="flex items-center gap-2">
                <Button as-child size="sm" class="bg-lime-500 hover:bg-lime-600 text-zinc-950">
                    <Link :href="`/environments/${environment.id}/projects/create`">
                        <Plus class="w-4 h-4 mr-1.5" />
                        New Project
                    </Link>
                </Button>
            </div>
        </header>

        <!-- WebSocket Connection Warning -->
        <div
            v-if="connectionError || !isProvisioningConfigured"
            class="mb-6 p-4 bg-amber-500/10 border border-amber-500/20 rounded-lg flex items-start gap-3"
        >
            <AlertCircle class="w-5 h-5 text-amber-400 flex-shrink-0 mt-0.5" />
            <div>
                <p class="text-amber-400 font-medium text-sm">Real-time updates unavailable</p>
                <p class="text-zinc-400 text-sm mt-1">
                    <span v-if="!isProvisioningConfigured">
                        Reverb is not configured for this environment. Status updates for
                        provisioning and deletion will not appear automatically.
                    </span>
                    <span v-else>
                        Could not connect to WebSocket: {{ connectionError }}. Status updates for
                        provisioning and deletion will not appear automatically.
                    </span>
                </p>
            </div>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-8 text-center">
            <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-3" />
            <p class="text-zinc-500">Loading projects...</p>
        </div>

        <!-- Empty State -->
        <div
            v-else-if="allProjects.length === 0"
            class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-8 text-center"
        >
            <FolderOpen class="w-12 h-12 mx-auto text-zinc-600 mb-3" />
            <h3 class="text-lg font-medium text-zinc-100 mb-2">No projects found</h3>
            <p class="text-zinc-400">Add project directories in the environment configuration.</p>
        </div>

        <!-- Projects Table -->
        <div v-else class="rounded-lg border border-zinc-800 bg-zinc-900/50 overflow-hidden">
            <!-- Table Header -->
            <div class="grid grid-cols-[1fr_100px_140px_160px] items-center gap-4 px-4 py-3 border-b border-zinc-800 bg-zinc-800/30">
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Project</span>
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Status</span>
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">PHP</span>
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide text-right">Actions</span>
            </div>

            <!-- Table Body -->
            <div>
                <div
                    v-for="site in allProjects"
                    :key="site.name"
                    class="grid grid-cols-[1fr_100px_140px_160px] items-center gap-4 px-4 py-3 border-b border-zinc-800/50 last:border-b-0 transition-colors hover:bg-zinc-800/30"
                    :class="{
                        'opacity-50': isProjectDeleting(site.name),
                        'bg-zinc-800/20': isProjectProvisioning(site),
                        'bg-lime-500/5': getProjectDeletionStatusValue(site.name) === 'deleted',
                        'bg-red-500/5':
                            isProjectDeleting(site.name) &&
                            getProjectDeletionStatusValue(site.name) !== 'deleted',
                    }"
                >
                    <!-- Site info -->
                    <div class="flex items-center gap-3 min-w-0">
                        <Check
                            v-if="getProjectDeletionStatusValue(site.name) === 'deleted'"
                            class="w-4 h-4 shrink-0 text-lime-400"
                        />
                        <Loader2
                            v-else-if="isProjectDeleting(site.name)"
                            class="w-4 h-4 shrink-0 text-red-400 animate-spin"
                        />
                        <Loader2
                            v-else-if="isProjectProvisioning(site)"
                            class="w-4 h-4 shrink-0 text-amber-400 animate-spin"
                        />
                        <Clock3
                            v-else-if="getProjectProvisionStatus(site) === 'queued'"
                            class="w-4 h-4 shrink-0 text-blue-400"
                        />
                        <AlertCircle
                            v-else-if="
                                getProjectProvisionStatus(site) === 'failed' ||
                                getProjectDeletionStatusValue(site.name) === 'delete_failed'
                            "
                            class="w-4 h-4 shrink-0 text-red-400"
                        />
                        <Package
                            v-else-if="site.project_type === 'laravel-package'"
                            class="w-4 h-4 shrink-0 text-purple-400"
                        />
                        <Terminal
                            v-else-if="site.project_type === 'cli'"
                            class="w-4 h-4 shrink-0 text-amber-400"
                        />
                        <Globe
                            v-else-if="site.has_public_folder"
                            class="w-4 h-4 shrink-0 text-lime-400"
                        />
                        <FolderOpen v-else class="w-4 h-4 shrink-0 text-zinc-500" />
                        <div class="min-w-0">
                            <p class="font-medium text-sm text-zinc-100 truncate">{{ site.display_name || site.name }}</p>
                            <p
                                v-if="
                                    site.github_repo &&
                                    !isProjectProvisioning(site) &&
                                    !isProjectDeleting(site.name)
                                "
                                class="text-xs text-zinc-500 truncate"
                            >
                                {{ site.github_repo }}
                            </p>
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="text-sm">
                        <!-- Deletion status -->
                        <span
                            v-if="getProjectDeletionStatusValue(site.name) === 'deleted'"
                            class="px-2 py-0.5 text-xs font-medium rounded-full bg-lime-500/15 text-lime-400"
                        >
                            Deleted
                        </span>
                        <span
                            v-else-if="getProjectDeletionStatusValue(site.name) === 'delete_failed'"
                            class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-500/15 text-red-400"
                        >
                            Failed
                        </span>
                        <span
                            v-else-if="isProjectDeleting(site.name)"
                            class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-500/15 text-red-400"
                        >
                            {{ deletionStatusLabels[getProjectDeletionStatusValue(site.name)!] }}
                        </span>
                        <!-- Provisioning status -->
                        <span
                            v-else-if="getProjectProvisionStatus(site) === 'failed'"
                            class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-500/15 text-red-400"
                        >
                            Failed
                        </span>
                        <span
                            v-else-if="getProjectProvisionStatus(site)"
                            class="px-2 py-0.5 text-xs font-medium rounded-full bg-amber-500/15 text-amber-400"
                        >
                            {{ statusLabels[getProjectProvisionStatus(site)!] }}
                        </span>
                        <span v-else class="text-zinc-500/50">—</span>
                    </div>

                    <!-- PHP Version Dropdown -->
                    <div>
                        <div
                            v-if="isProjectProvisioning(site) || isProjectDeleting(site.name)"
                            class="text-sm text-zinc-500 font-mono"
                        >
                            {{ site.php_version || defaultPhpVersion }}
                        </div>
                        <div
                            v-else-if="changingPhpFor === site.name"
                            class="flex items-center gap-2"
                        >
                            <Loader2 class="w-4 h-4 text-zinc-400 animate-spin" />
                            <span class="text-sm text-zinc-500">...</span>
                        </div>
                        <select
                            v-else
                            :value="site.php_version || defaultPhpVersion"
                            @change="
                                (e) =>
                                    changePhpVersion(
                                        site,
                                        (e.target as HTMLSelectElement).value,
                                    )
                            "
                            class="h-8 w-[110px] text-xs py-1 pl-2 pr-7 font-mono bg-zinc-800/50 border border-zinc-700 rounded-md text-zinc-100 hover:bg-zinc-800"
                            :disabled="!site.has_public_folder"
                        >
                            <option
                                v-for="version in availablePhpVersions"
                                :key="version"
                                :value="version"
                            >
                                PHP {{ version }}
                            </option>
                        </select>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-1">
                        <div
                            v-if="getProjectDeletionStatusValue(site.name) === 'deleted'"
                            class="text-xs text-lime-400"
                        >
                            Deleted
                        </div>
                        <div
                            v-else-if="isProjectDeleting(site.name)"
                            class="text-xs text-red-400"
                        >
                            Deleting...
                        </div>
                        <div
                            v-else-if="isProjectProvisioning(site)"
                            class="text-xs text-zinc-500"
                        >
                            {{ statusLabels[getProjectProvisionStatus(site) ?? 'queued'] }}
                        </div>
                        <template v-else>
                            <Button
                                v-if="site.has_public_folder && site.domain"
                                @click="openSite(site.domain)"
                                variant="outline"
                                size="sm"
                                class="h-8 px-3 bg-transparent border-zinc-700 text-zinc-300 hover:bg-zinc-800"
                            >
                                <ExternalLink class="w-3.5 h-3.5 mr-1.5" />
                                Open
                            </Button>
                            <div class="flex items-center gap-0.5 opacity-60 hover:opacity-100 transition-opacity">
                                <Button
                                    v-if="$page.props.multi_environment"
                                    @click="openInEditor(site.path)"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="h-8 w-8 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800"
                                    :title="`Open in ${editor.name}`"
                                >
                                    <Code class="w-3.5 h-3.5" />
                                </Button>
                                <Button
                                    v-if="
                                        site.has_public_folder &&
                                        !isProjectProvisioning(site) &&
                                        !isProjectDeleting(site.name)
                                    "
                                    @click="rebuildProject(site)"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="h-8 w-8 text-zinc-400 hover:text-amber-400 hover:bg-zinc-800"
                                    :disabled="rebuildingProject === site.name"
                                    title="Rebuild project"
                                >
                                    <Loader2
                                        v-if="rebuildingProject === site.name"
                                        class="w-3.5 h-3.5 animate-spin"
                                    />
                                    <RefreshCw v-else class="w-3.5 h-3.5" />
                                </Button>
                                <Button
                                    @click="confirmDeleteProject(site)"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="h-8 w-8 text-zinc-400 hover:text-red-400 hover:bg-red-500/10"
                                    title="Delete project"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                </Button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-between mt-4 px-1">
            <div class="flex items-center gap-4 text-xs text-zinc-500">
                <div class="flex items-center gap-1.5">
                    <Globe class="h-3.5 w-3.5 text-lime-400" />
                    <span>Has public folder</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <FolderOpen class="h-3.5 w-3.5" />
                    <span>No public folder</span>
                </div>
            </div>
            <div class="flex items-center gap-1.5 text-xs">
                <span v-if="isConnected" class="inline-flex items-center gap-1.5 text-lime-400">
                    <span class="w-2 h-2 rounded-full bg-lime-400"></span>
                    Live updates
                </span>
                <span v-else class="inline-flex items-center gap-1.5 text-amber-400">
                    <span class="w-2 h-2 rounded-full bg-amber-400"></span>
                    No live updates
                </span>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" title="Delete Project" @close="showDeleteModal = false">
            <div class="p-6">
                <p class="text-zinc-300 mb-4">
                    Are you sure you want to delete
                    <strong class="text-white">{{ projectToDelete?.name }}</strong
                    >?
                </p>
                <p class="text-zinc-400 text-sm mb-4">This will:</p>
                <ul class="text-zinc-400 text-sm mb-4 list-disc list-inside space-y-1">
                    <li>Remove the site directory from the server</li>
                </ul>

                <!-- Delete database checkbox -->
                <div class="flex items-center gap-3 mb-6">
                    <Checkbox
                        id="delete-database"
                        v-model="deleteDatabase"
                        class="border-zinc-600 data-[state=checked]:bg-primary data-[state=checked]:border-primary data-[state=checked]:text-primary-foreground"
                    />
                    <Label for="delete-database" class="text-zinc-300 text-sm cursor-pointer">
                        Also delete the database
                    </Label>
                </div>

                <p class="text-red-400 text-sm mb-6">This action cannot be undone.</p>

                <div
                    v-if="deleteError"
                    class="mb-4 p-3 bg-red-400/10 border border-red-400/20 rounded-lg"
                >
                    <p class="text-red-400 text-sm">{{ deleteError }}</p>
                </div>

                <div class="flex justify-end gap-3">
                    <Button
                        @click="showDeleteModal = false"
                        variant="outline"
                        :disabled="deleting"
                    >
                        Cancel
                    </Button>
                    <Button
                        @click="deleteProject"
                        variant="destructive"
                        :disabled="deleting"
                    >
                        <Loader2 v-if="deleting" class="w-4 h-4 animate-spin" />
                        <Trash2 v-else class="w-4 h-4" />
                        {{ deleting ? 'Deleting...' : 'Delete Project' }}
                    </Button>
                </div>
            </div>
        </Modal>
    </div>
</template>
