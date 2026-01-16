<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import api from '@/lib/axios';
import Layout from '@/layouts/Layout.vue';
import Heading from '@/components/Heading.vue';
import Modal from '@/components/Modal.vue';
import {
    ArrowLeft,
    Boxes,
    Plus,
    Trash2,
    ExternalLink,
    FolderGit2,
    Loader2,
    X,
    Terminal,
    Package,
    Link2,
    Unlink,
    ChevronDown,
    ChevronRight,
} from 'lucide-vue-next';
import EditorIcon from '@/components/icons/EditorIcon.vue';
import { Button, Label, Badge } from '@hardimpactdev/craft-ui';
import { toast } from 'vue-sonner';

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

interface WorkspaceProject {
    name: string;
    path: string;
}

interface Workspace {
    name: string;
    path: string;
    projects: WorkspaceProject[];
    project_count: number;
    has_workspace_file: boolean;
    has_claude_md: boolean;
}

interface Project {
    name: string;
    display_name?: string;
    path: string;
    has_public_folder: boolean;
}

interface LinkedPackage {
    name: string;
    path: string;
}

const props = defineProps<{
    environment: Environment;
    workspaceName: string;
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

defineOptions({
    layout: Layout,
});

// Workspace data loading
const workspace = ref<Workspace | null>(null);
const loadingWorkspace = ref(true);
const workspaceError = ref<string | null>(null);

const showAddProjectModal = ref(false);
const selectedProject = ref('');
const addingProject = ref(false);
const removingProject = ref<string | null>(null);

async function loadWorkspace() {
    loadingWorkspace.value = true;
    workspaceError.value = null;
    try {
        const { data: result } = await api.get(getApiUrl(`/workspaces/${props.workspaceName}`));
        if (result.success && result.data) {
            workspace.value = result.data;
        } else {
            workspaceError.value = result.error || 'Workspace not found';
        }
    } catch (error) {
        if (axios.isCancel(error)) return;
        console.error('Failed to load workspace:', error);
        workspaceError.value = 'Failed to load workspace';
    } finally {
        loadingWorkspace.value = false;
    }
}

// Lazy-loaded projects for "Add project" dropdown
const allProjects = ref<Project[]>([]);
const loadingProjects = ref(false);

// Package linking state
const expandedProjects = ref<Set<string>>(new Set());
const linkedPackages = ref<Record<string, LinkedPackage[]>>({});
const loadingPackages = ref<Set<string>>(new Set());
const showLinkPackageModal = ref(false);
const linkingToProject = ref<string | null>(null);
const selectedPackage = ref('');
const linkingPackage = ref(false);
const unlinkingPackage = ref<string | null>(null);

// Projects available to add (not already in workspace)
const availableProjects = computed(() => {
    if (!workspace.value) return [];
    const workspaceProjectNames = new Set(
        workspace.value.projects.map((p: WorkspaceProject) => p.name),
    );
    return allProjects.value.filter((p) => !workspaceProjectNames.has(p.name));
});

// Load projects when opening the add project modal
const openAddProjectModal = async () => {
    showAddProjectModal.value = true;

    // Only load if not already loaded
    if (allProjects.value.length === 0) {
        loadingProjects.value = true;
        try {
            const { data: result } = await api.get(getApiUrl('/projects'));
            if (result.success && result.data?.projects) {
                allProjects.value = result.data.projects;
            }
        } catch (error) {
            if (axios.isCancel(error)) return;
            console.error('Failed to load projects:', error);
        } finally {
            loadingProjects.value = false;
        }
    }
};

const openInEditor = async () => {
    if (!workspace.value) return;
    const user = props.environment.user;
    const host = props.environment.host;
    const workspacePath = workspace.value.path;
    const workspaceFile = `${workspacePath}/${workspace.value.name}.code-workspace`;

    const url = `${props.editor.scheme}://vscode-remote/ssh-remote+${user}@${host}${workspaceFile}?windowId=_blank`;

    try {
        await api.post('/open-external', { url });
    } catch {
        // Silent fail for opening URLs
    }
};

const openInTerminal = async () => {
    if (!workspace.value) return;
    try {
        await api.post('/open-terminal', {
            user: props.environment.user,
            host: props.environment.host,
            path: workspace.value.path,
        });
    } catch {
        // Silent fail for opening terminal
    }
};

const addProject = async () => {
    if (!selectedProject.value || !workspace.value) return;

    const projectName = selectedProject.value;
    addingProject.value = true;

    try {
        const { data } = await api.post(
            getApiUrl(`/workspaces/${workspace.value.name}/projects`),
            { project: projectName },
            {},
        );

        if (data.success && data.workspace) {
            workspace.value = data.workspace;
            toast.success(`Project "${projectName}" added to workspace`);
        } else {
            toast.error('Failed to add project', {
                description: data.error || 'Unknown error',
            });
        }

        showAddProjectModal.value = false;
        selectedProject.value = '';
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        addingProject.value = false;
    }
};

const removeProject = async (projectName: string) => {
    if (!workspace.value) return;
    removingProject.value = projectName;

    try {
        const { data } = await api.delete(
            getApiUrl(`/workspaces/${workspace.value.name}/projects/${projectName}`),
            {},
        );

        if (data.success && data.workspace) {
            workspace.value = data.workspace;
            toast.success(`Project "${projectName}" removed from workspace`);
        } else {
            toast.error('Failed to remove project', {
                description: data.error || 'Unknown error',
            });
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        removingProject.value = null;
    }
};

const getProjectDisplayName = (name: string) => {
    const project = allProjects.value.find((p: Project) => p.name === name);
    return project?.display_name || name;
};

// Package linking functions
const toggleProjectExpanded = (projectName: string) => {
    if (expandedProjects.value.has(projectName)) {
        expandedProjects.value.delete(projectName);
    } else {
        expandedProjects.value.add(projectName);
        // Load linked packages if not already loaded
        if (!linkedPackages.value[projectName]) {
            loadLinkedPackages(projectName);
        }
    }
    // Force reactivity
    expandedProjects.value = new Set(expandedProjects.value);
};

const loadLinkedPackages = async (projectName: string) => {
    loadingPackages.value.add(projectName);
    loadingPackages.value = new Set(loadingPackages.value);

    try {
        const { data } = await api.get(getApiUrl(`/packages/${projectName}/linked`), {});

        if (data.success) {
            linkedPackages.value[projectName] = data.linked_packages || [];
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        loadingPackages.value.delete(projectName);
        loadingPackages.value = new Set(loadingPackages.value);
    }
};

// Get packages available to link (other projects in workspace that aren't the target app)
const availablePackagesToLink = computed(() => {
    if (!linkingToProject.value || !workspace.value) return [];
    return workspace.value.projects.filter((p) => p.name !== linkingToProject.value);
});

const openLinkPackageModal = (projectName: string) => {
    linkingToProject.value = projectName;
    selectedPackage.value = '';
    showLinkPackageModal.value = true;
};

const linkPackage = async () => {
    if (!selectedPackage.value || !linkingToProject.value) return;

    const packageName = selectedPackage.value;
    const projectName = linkingToProject.value;
    linkingPackage.value = true;

    try {
        const { data } = await api.post(
            getApiUrl(`/packages/${projectName}/link`),
            { package: packageName },
            {},
        );

        if (data.success) {
            toast.success(`Package "${packageName}" linked to "${projectName}"`);
            // Reload linked packages for this project
            await loadLinkedPackages(projectName);
        } else {
            toast.error('Failed to link package', {
                description: data.error || 'Unknown error',
            });
        }

        showLinkPackageModal.value = false;
        selectedPackage.value = '';
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        linkingPackage.value = false;
    }
};

const unlinkPackage = async (projectName: string, packageName: string) => {
    unlinkingPackage.value = `${projectName}:${packageName}`;

    try {
        const { data } = await api.delete(
            getApiUrl(`/packages/${projectName}/unlink/${encodeURIComponent(packageName)}`),
            {},
        );

        if (data.success) {
            toast.success(`Package "${packageName}" unlinked from "${projectName}"`);
            // Reload linked packages for this project
            await loadLinkedPackages(projectName);
        } else {
            toast.error('Failed to unlink package', {
                description: data.error || 'Unknown error',
            });
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        unlinkingPackage.value = null;
    }
};

onMounted(() => {
    loadWorkspace();
});
</script>

<template>
    <Head :title="`${workspace?.name || workspaceName} - Workspaces`" />

    <!-- Loading State -->
    <div v-if="loadingWorkspace" class="space-y-6">
        <div class="flex items-center gap-4">
            <Link
                :href="`/environments/${environment.id}/workspaces`"
                class="p-2 rounded-lg hover:bg-white/5 text-zinc-400 hover:text-white"
            >
                <ArrowLeft class="w-5 h-5" />
            </Link>
            <div>
                <div class="flex items-center gap-2">
                    <Boxes class="w-5 h-5 text-lime-400" />
                    <h1 class="text-xl font-semibold text-white">{{ workspaceName }}</h1>
                </div>
            </div>
        </div>
        <div class="border border-zinc-800 rounded-xl p-12 text-center">
            <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-4" />
            <p class="text-zinc-400">Loading workspace...</p>
        </div>
    </div>

    <!-- Error State -->
    <div v-else-if="workspaceError" class="space-y-6">
        <div class="flex items-center gap-4">
            <Link
                :href="`/environments/${environment.id}/workspaces`"
                class="p-2 rounded-lg hover:bg-white/5 text-zinc-400 hover:text-white"
            >
                <ArrowLeft class="w-5 h-5" />
            </Link>
            <div>
                <h1 class="text-xl font-semibold text-white">Workspace Not Found</h1>
            </div>
        </div>
        <div class="border border-zinc-800 rounded-xl p-12 text-center">
            <p class="text-red-400">{{ workspaceError }}</p>
            <Button as-child variant="outline" class="mt-4">
                <Link :href="`/environments/${environment.id}/workspaces`">
                    Back to Workspaces
                </Link>
            </Button>
        </div>
    </div>

    <!-- Loaded Content -->
    <div v-else-if="workspace" class="space-y-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <Link
                    :href="`/environments/${environment.id}/workspaces`"
                    class="p-2 rounded-lg hover:bg-white/5 text-zinc-400 hover:text-white"
                >
                    <ArrowLeft class="w-5 h-5" />
                </Link>
                <div>
                    <div class="flex items-center gap-2">
                        <Boxes class="w-5 h-5 text-lime-400" />
                        <h1 class="text-xl font-semibold text-white">{{ workspace.name }}</h1>
                    </div>
                    <p class="text-sm text-zinc-400 mt-1">
                        {{ workspace.project_count }} project{{
                            workspace.project_count !== 1 ? 's' : ''
                        }}
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <Button @click="openInTerminal" variant="outline" title="Open in Terminal">
                    <Terminal class="w-4 h-4 mr-2" />
                    SSH
                </Button>
                <Button
                    v-if="workspace.has_workspace_file"
                    @click="openInEditor"
                    variant="outline"
                >
                    <EditorIcon :editor="editor.scheme" class="w-4 h-4 mr-2" />
                    Open in {{ editor.name }}
                </Button>
                <Button @click="openAddProjectModal" variant="secondary">
                    <Plus class="w-4 h-4 mr-2" />
                    Add Project
                </Button>
            </div>
        </div>

        <!-- Projects List -->
        <div class="border border-zinc-800 rounded-xl px-0.5 pt-4 pb-0.5">
            <div class="px-4 mb-4">
                <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider">Projects</h2>
            </div>
            <div class="border border-zinc-700/50 rounded-lg overflow-hidden">
                <div v-if="workspace.projects.length === 0" class="p-8 text-center">
                    <FolderGit2 class="w-10 h-10 mx-auto text-muted-foreground mb-3" />
                    <p class="text-muted-foreground mb-4">No projects in this workspace yet.</p>
                    <Button @click="openAddProjectModal" variant="secondary">
                        <Plus class="w-4 h-4 mr-2" />
                        Add Your First Project
                    </Button>
                </div>
                <div v-else class="divide-y divide-zinc-700/50">
                    <div
                        v-for="project in workspace.projects"
                        :key="project.name"
                        class="bg-zinc-800/30"
                    >
                        <div
                            class="flex items-center justify-between px-4 py-3 hover:bg-zinc-700/30"
                        >
                            <div class="flex items-center gap-3">
                                <button
                                    @click="toggleProjectExpanded(project.name)"
                                    class="p-0.5 rounded hover:bg-white/10 text-zinc-400"
                                >
                                    <ChevronDown
                                        v-if="expandedProjects.has(project.name)"
                                        class="w-4 h-4"
                                    />
                                    <ChevronRight v-else class="w-4 h-4" />
                                </button>
                                <FolderGit2 class="w-4 h-4 text-lime-400" />
                                <div>
                                    <span class="font-medium text-white">{{
                                        getProjectDisplayName(project.name)
                                    }}</span>
                                    <span class="ml-2 text-xs text-zinc-500 font-mono">{{
                                        project.name
                                    }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <Button
                                    @click="openLinkPackageModal(project.name)"
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground hover:text-lime-400"
                                    title="Link a package"
                                >
                                    <Link2 class="w-3.5 h-3.5" />
                                </Button>
                                <Button
                                    @click="removeProject(project.name)"
                                    variant="ghost"
                                    size="sm"
                                    class="text-muted-foreground hover:text-red-400"
                                    :disabled="removingProject === project.name"
                                >
                                    <Loader2
                                        v-if="removingProject === project.name"
                                        class="w-3.5 h-3.5 animate-spin"
                                    />
                                    <X v-else class="w-3.5 h-3.5" />
                                </Button>
                            </div>
                        </div>

                        <!-- Expanded: Linked Packages -->
                        <div
                            v-if="expandedProjects.has(project.name)"
                            class="px-4 pb-3 pl-12 border-t border-zinc-700/30"
                        >
                            <div class="pt-3">
                                <div class="flex items-center gap-2 mb-2">
                                    <Package class="w-3.5 h-3.5 text-zinc-500" />
                                    <span class="text-xs text-zinc-500 uppercase tracking-wider"
                                        >Linked Packages</span
                                    >
                                </div>

                                <div
                                    v-if="loadingPackages.has(project.name)"
                                    class="py-2 text-center"
                                >
                                    <Loader2 class="w-4 h-4 animate-spin text-zinc-500 mx-auto" />
                                </div>

                                <div
                                    v-else-if="
                                        !linkedPackages[project.name] ||
                                        linkedPackages[project.name].length === 0
                                    "
                                    class="py-2"
                                >
                                    <p class="text-xs text-zinc-500">No packages linked.</p>
                                </div>

                                <div v-else class="space-y-1">
                                    <div
                                        v-for="pkg in linkedPackages[project.name]"
                                        :key="pkg.name"
                                        class="flex items-center justify-between py-1.5 px-2 rounded bg-zinc-700/30"
                                    >
                                        <div class="flex items-center gap-2">
                                            <Package class="w-3.5 h-3.5 text-amber-400" />
                                            <span class="text-sm text-zinc-300 font-mono">{{
                                                pkg.name
                                            }}</span>
                                        </div>
                                        <button
                                            @click="unlinkPackage(project.name, pkg.name)"
                                            class="p-1 rounded hover:bg-zinc-600 text-zinc-400 hover:text-red-400"
                                            :disabled="
                                                unlinkingPackage === `${project.name}:${pkg.name}`
                                            "
                                            title="Unlink package"
                                        >
                                            <Loader2
                                                v-if="
                                                    unlinkingPackage ===
                                                    `${project.name}:${pkg.name}`
                                                "
                                                class="w-3 h-3 animate-spin"
                                            />
                                            <Unlink v-else class="w-3 h-3" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workspace Info -->
        <div class="border border-zinc-800 rounded-xl p-4">
            <h2 class="text-sm font-medium text-zinc-400 uppercase tracking-wider mb-3">
                Workspace Info
            </h2>
            <div class="space-y-2 text-sm">
                <div class="flex items-center gap-2">
                    <span class="text-zinc-500">Path:</span>
                    <span class="font-mono text-zinc-300">{{ workspace.path }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-zinc-500">Workspace file:</span>
                    <span class="font-mono text-zinc-300">{{ workspace.name }}.code-workspace</span>
                    <span
                        v-if="workspace.has_workspace_file"
                        class="text-xs px-1.5 py-0.5 rounded bg-lime-400/10 text-lime-400"
                    >
                        Ready
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-zinc-500">CLAUDE.md:</span>
                    <span
                        v-if="workspace.has_claude_md"
                        class="text-xs px-1.5 py-0.5 rounded bg-lime-400/10 text-lime-400"
                    >
                        Present
                    </span>
                    <span v-else class="text-zinc-400">Not found</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Project Modal -->
    <Modal
        :show="showAddProjectModal"
        title="Add Project to Workspace"
        @close="showAddProjectModal = false"
    >
        <div class="p-6">
            <div v-if="loadingProjects" class="text-center py-6">
                <Loader2 class="w-6 h-6 mx-auto text-zinc-400 animate-spin mb-2" />
                <p class="text-zinc-400">Loading projects...</p>
            </div>

            <div v-else-if="availableProjects.length === 0" class="text-center py-6">
                <p class="text-zinc-400">All projects are already in this workspace.</p>
            </div>

            <div v-else>
                <label for="project-select" class="block text-sm font-medium text-zinc-300 mb-2">
                    Select a project
                </label>
                <select id="project-select" v-model="selectedProject" class="w-full">
                    <option value="">Choose a project...</option>
                    <option
                        v-for="project in availableProjects"
                        :key="project.name"
                        :value="project.name"
                    >
                        {{ project.display_name || project.name }}
                    </option>
                </select>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <Button @click="showAddProjectModal = false" variant="ghost">Cancel</Button>
                <Button
                    @click="addProject"
                    variant="secondary"
                    :disabled="!selectedProject || addingProject"
                >
                    <Loader2 v-if="addingProject" class="w-4 h-4 mr-2 animate-spin" />
                    Add Project
                </Button>
            </div>
        </div>
    </Modal>

    <!-- Link Package Modal -->
    <Modal :show="showLinkPackageModal" title="Link Package" @close="showLinkPackageModal = false">
        <div class="p-6">
            <p class="text-zinc-400 mb-4">
                Link a package from this workspace to
                <span class="text-white font-medium">{{ linkingToProject }}</span> for local
                development.
            </p>

            <div v-if="availablePackagesToLink.length === 0" class="text-center py-6">
                <p class="text-zinc-400">
                    No other projects in this workspace to link as packages.
                </p>
            </div>

            <div v-else>
                <label for="package-select" class="block text-sm font-medium text-zinc-300 mb-2">
                    Select a package to link
                </label>
                <select id="package-select" v-model="selectedPackage" class="w-full">
                    <option value="">Choose a package...</option>
                    <option
                        v-for="project in availablePackagesToLink"
                        :key="project.name"
                        :value="project.name"
                    >
                        {{ getProjectDisplayName(project.name) }}
                    </option>
                </select>
                <p class="text-xs text-zinc-500 mt-2">
                    The selected project will be symlinked as a Composer dependency.
                </p>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <Button @click="showLinkPackageModal = false" variant="ghost">Cancel</Button>
                <Button
                    @click="linkPackage"
                    variant="secondary"
                    :disabled="!selectedPackage || linkingPackage"
                >
                    <Loader2 v-if="linkingPackage" class="w-4 h-4 mr-2 animate-spin" />
                    <Link2 v-else class="w-4 h-4 mr-2" />
                    Link Package
                </Button>
            </div>
        </div>
    </Modal>
</template>
