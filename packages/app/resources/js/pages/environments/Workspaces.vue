<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import api from '@/lib/axios';
import Layout from '@/layouts/Layout.vue';
import Heading from '@/components/Heading.vue';
import Modal from '@/components/Modal.vue';
import { Boxes, Plus, Trash2, ExternalLink, FolderGit2, Loader2, Terminal } from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import EditorIcon from '@/components/icons/EditorIcon.vue';
import { Button, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '@hardimpactdev/craft-ui';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
    external_access: boolean;
    external_host: string | null;
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

// Async data loading
const workspaces = ref<Workspace[]>([]);
const loading = ref(true);

async function loadWorkspaces() {
    loading.value = true;
    try {
        const { data: result } = await api.get(getApiUrl('/workspaces'));
        if (result.success && result.data?.workspaces) {
            workspaces.value = result.data.workspaces;
        }
    } catch (error) {
        if (axios.isCancel(error)) return;
        console.error('Failed to load workspaces:', error);
        // Error toast handled by axios interceptor
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    loadWorkspaces();
});

defineOptions({
    layout: Layout,
});

const deletingWorkspace = ref<string | null>(null);
const showDeleteModal = ref(false);
const workspaceToDelete = ref<string | null>(null);

const openInEditor = (workspace: Workspace) => {
    const workspacePath = workspace.path;
    const workspaceFile = `${workspacePath}/${workspace.name}.code-workspace`;

    let url: string;
    if (props.environment.external_access || !props.environment.is_local) {
        // Use SSH remote URL for external access or remote environments
        const user = props.environment.user;
        const host = props.environment.external_access && props.environment.external_host
            ? props.environment.external_host
            : props.environment.host;
        url = `${props.editor.scheme}://vscode-remote/ssh-remote+${user}@${host}${workspaceFile}?windowId=_blank`;
    } else {
        // Use local file URL
        url = `${props.editor.scheme}://file${workspaceFile}`;
    }
    window.open(url, '_blank');
};

const openInTerminal = (workspace: Workspace) => {
    const user = props.environment.user;
    const host = props.environment.external_access && props.environment.external_host
        ? props.environment.external_host
        : props.environment.host;
    // Use ssh:// protocol with path - OS handles opening the terminal
    const url = `ssh://${user}@${host}${workspace.path}`;
    window.open(url, '_self');
};

const confirmDelete = (name: string) => {
    workspaceToDelete.value = name;
    showDeleteModal.value = true;
};

const deleteWorkspace = async () => {
    if (!workspaceToDelete.value) return;

    const workspaceName = workspaceToDelete.value;
    deletingWorkspace.value = workspaceName;
    showDeleteModal.value = false;

    try {
        const { data } = await api.delete(getApiUrl(`/workspaces/${workspaceName}`));
        if (data.success) {
            toast.success(`Workspace "${workspaceName}" deleted successfully`);
            await loadWorkspaces();
        } else {
            toast.error('Failed to delete workspace', {
                description: data.error || 'Unknown error',
            });
        }
    } catch {
        // Error toast handled by axios interceptor
    } finally {
        deletingWorkspace.value = null;
        workspaceToDelete.value = null;
    }
};
</script>

<template>
    <Head :title="`Workspaces - ${environment.name}`" />

    <div>
        <!-- Header -->
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">Workspaces</h1>
                <p class="text-sm text-zinc-500 mt-1">Group related projects together for easier management</p>
            </div>
            <div class="flex items-center gap-2">
                <Button as-child size="sm" class="bg-lime-500 hover:bg-lime-600 text-zinc-950">
                    <Link :href="`/environments/${environment.id}/workspaces/create`">
                        <Plus class="w-4 h-4 mr-1.5" />
                        New Workspace
                    </Link>
                </Button>
            </div>
        </header>

        <!-- Loading State -->
        <div v-if="loading" class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-12 text-center">
            <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-4" />
            <p class="text-zinc-500">Loading workspaces...</p>
        </div>

        <!-- Empty State -->
        <div
            v-else-if="workspaces.length === 0"
            class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-12 text-center"
        >
            <Boxes class="w-12 h-12 mx-auto text-zinc-600 mb-4" />
            <h3 class="text-lg font-medium text-zinc-100 mb-2">No workspaces yet</h3>
            <p class="text-zinc-400 mb-6">Create a workspace to group related projects together.</p>
            <Button as-child size="sm" class="bg-lime-500 hover:bg-lime-600 text-zinc-950">
                <Link :href="`/environments/${environment.id}/workspaces/create`">
                    <Plus class="w-4 h-4 mr-1.5" />
                    Create Your First Workspace
                </Link>
            </Button>
        </div>

        <!-- Workspaces Table -->
        <div v-else class="rounded-lg border border-zinc-800 bg-zinc-900/50 overflow-hidden">
            <!-- Table Header -->
            <div class="grid grid-cols-[1fr_1fr_140px] items-center gap-4 px-4 py-3 border-b border-zinc-800 bg-zinc-800/30">
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Workspace</span>
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Projects</span>
                <span class="text-xs font-medium text-zinc-500 uppercase tracking-wide text-right">Actions</span>
            </div>

            <!-- Table Body -->
            <div>
                <div
                    v-for="workspace in workspaces"
                    :key="workspace.name"
                    class="grid grid-cols-[1fr_1fr_140px] items-center gap-4 px-4 py-4 border-b border-zinc-800/50 last:border-b-0 transition-colors hover:bg-zinc-800/30"
                >
                    <!-- Workspace name -->
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="flex h-8 w-8 items-center justify-center rounded-md bg-lime-500/15">
                            <Boxes class="h-4 w-4 text-lime-400" />
                        </div>
                        <Link
                            :href="`/environments/${environment.id}/workspaces/${workspace.name}`"
                            class="font-medium text-sm text-zinc-100 hover:text-lime-400 transition-colors"
                        >
                            {{ workspace.name }}
                        </Link>
                    </div>

                    <!-- Projects -->
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-zinc-500">{{ workspace.project_count }} project{{ workspace.project_count !== 1 ? 's' : '' }}</span>
                        <div v-if="workspace.projects.length > 0" class="flex -space-x-2">
                            <div
                                v-for="project in workspace.projects.slice(0, 3)"
                                :key="project.name"
                                class="flex h-7 w-7 items-center justify-center rounded-full bg-zinc-800 border-2 border-zinc-900 text-xs"
                                :title="project.name"
                            >
                                <FolderGit2 class="h-3 w-3 text-zinc-400" />
                            </div>
                            <div
                                v-if="workspace.projects.length > 3"
                                class="flex h-7 w-7 items-center justify-center rounded-full bg-zinc-800 border-2 border-zinc-900 text-xs font-medium text-zinc-400"
                            >
                                +{{ workspace.projects.length - 3 }}
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-end gap-2">
                        <Button
                            as-child
                            variant="outline"
                            size="sm"
                            class="h-8 px-3 bg-transparent border-zinc-700 text-zinc-300 hover:bg-zinc-800"
                        >
                            <Link :href="`/environments/${environment.id}/workspaces/${workspace.name}`">
                                Manage
                            </Link>
                        </Button>
                        <div class="flex items-center gap-0.5 opacity-60 hover:opacity-100 transition-opacity">
                            <Button
                                v-if="environment.external_access"
                                @click="openInTerminal(workspace)"
                                variant="ghost"
                                size="icon-sm"
                                class="h-8 w-8 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800"
                                title="Open in Terminal"
                            >
                                <Terminal class="w-3.5 h-3.5" />
                            </Button>
                            <Button
                                v-if="workspace.has_workspace_file"
                                @click="openInEditor(workspace)"
                                variant="ghost"
                                size="icon-sm"
                                class="h-8 w-8 text-zinc-400 hover:text-zinc-200 hover:bg-zinc-800"
                                :title="`Open in ${editor.name}`"
                            >
                                <EditorIcon :editor="editor.scheme" class="w-3.5 h-3.5" />
                            </Button>
                            <Button
                                @click="confirmDelete(workspace.name)"
                                variant="ghost"
                                size="icon-sm"
                                class="h-8 w-8 text-zinc-400 hover:text-red-400 hover:bg-red-500/10"
                                :disabled="deletingWorkspace === workspace.name"
                                title="Delete workspace"
                            >
                                <Loader2
                                    v-if="deletingWorkspace === workspace.name"
                                    class="w-3.5 h-3.5 animate-spin"
                                />
                                <Trash2 v-else class="w-3.5 h-3.5" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <Modal :show="showDeleteModal" title="Delete Workspace" @close="showDeleteModal = false">
        <div class="p-6">
            <p class="text-zinc-400 mb-6">
                Are you sure you want to delete the workspace "{{ workspaceToDelete }}"? This will
                remove the workspace directory and symlinks, but won't delete the actual projects.
            </p>
            <div class="flex justify-end gap-3">
                <Button @click="showDeleteModal = false" variant="ghost">Cancel</Button>
                <Button @click="deleteWorkspace" variant="destructive">
                    Delete Workspace
                </Button>
            </div>
        </div>
    </Modal>
</template>
