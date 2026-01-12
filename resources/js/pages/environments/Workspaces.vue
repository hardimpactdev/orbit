<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import api from '@/lib/axios';
import Layout from '@/layouts/Layout.vue';
import Heading from '@/components/Heading.vue';
import Modal from '@/components/Modal.vue';
import { Boxes, Plus, Trash2, ExternalLink, FolderGit2, Loader2, Terminal } from 'lucide-vue-next';
import EditorIcon from '@/components/icons/EditorIcon.vue';

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

const openInEditor = async (workspace: Workspace) => {
    const user = props.environment.user;
    const host = props.environment.host;
    const workspacePath = workspace.path;
    const workspaceFile = `${workspacePath}/${workspace.name}.code-workspace`;

    // Open the .code-workspace file in the editor via SSH remote
    const url = `${props.editor.scheme}://vscode-remote/ssh-remote+${user}@${host}${workspaceFile}?windowId=_blank`;

    try {
        await api.post('/open-external', { url });
    } catch {
        // Silent fail for opening URLs
    }
};

const openInTerminal = async (workspace: Workspace) => {
    try {
        await api.post('/open-terminal', {
            user: props.environment.user,
            host: props.environment.host,
            path: workspace.path,
        });
    } catch {
        // Silent fail for opening terminal
    }
};

const confirmDelete = (name: string) => {
    workspaceToDelete.value = name;
    showDeleteModal.value = true;
};

const deleteWorkspace = async () => {
    if (!workspaceToDelete.value) return;

    deletingWorkspace.value = workspaceToDelete.value;
    showDeleteModal.value = false;

    try {
        const { data } = await api.delete(getApiUrl(`/workspaces/${workspaceToDelete.value}`));
        if (data.success) {
            await loadWorkspaces();
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

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <Heading title="Workspaces" description="Group related projects together for easier management" />
            <Link
                :href="`/environments/${environment.id}/workspaces/create`"
                class="btn btn-secondary"
            >
                <Plus class="w-4 h-4 mr-2" />
                New Workspace
            </Link>
        </div>

        <!-- Loading State -->
        <div
            v-if="loading"
            class="border border-zinc-800 rounded-xl p-12 text-center"
        >
            <Loader2 class="w-8 h-8 mx-auto text-zinc-600 animate-spin mb-4" />
            <p class="text-zinc-400">Loading workspaces...</p>
        </div>

        <!-- Empty State -->
        <div
            v-else-if="workspaces.length === 0"
            class="border border-zinc-800 rounded-xl p-12 text-center"
        >
            <Boxes class="w-12 h-12 mx-auto text-zinc-600 mb-4" />
            <h3 class="text-lg font-medium text-white mb-2">No workspaces yet</h3>
            <p class="text-zinc-400 mb-6">
                Create a workspace to group related projects together.
            </p>
            <Link
                :href="`/environments/${environment.id}/workspaces/create`"
                class="btn btn-secondary"
            >
                <Plus class="w-4 h-4 mr-2" />
                Create Your First Workspace
            </Link>
        </div>

        <!-- Workspaces List -->
        <div v-else class="border border-zinc-800 rounded-xl px-0.5 pt-4 pb-0.5">
            <div class="border border-zinc-700/50 rounded-lg overflow-hidden">
                <table class="table-catalyst w-full border-separate" style="border-spacing: 0 2px;">
                    <thead>
                        <tr class="bg-zinc-800/30">
                            <th class="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider rounded-l-lg">
                                Workspace
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase tracking-wider">
                                Projects
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-zinc-500 uppercase tracking-wider rounded-r-lg">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="workspace in workspaces"
                            :key="workspace.name"
                            class="bg-zinc-800/30 hover:bg-zinc-700/30"
                        >
                            <td class="px-4 py-3 rounded-l-lg">
                                <Link
                                    :href="`/environments/${environment.id}/workspaces/${workspace.name}`"
                                    class="flex items-center gap-3 hover:text-lime-400"
                                >
                                    <Boxes class="w-4 h-4 text-lime-400" />
                                    <span class="font-medium text-white">
                                        {{ workspace.name }}
                                    </span>
                                </Link>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-400">
                                        {{ workspace.project_count }} project{{ workspace.project_count !== 1 ? 's' : '' }}
                                    </span>
                                    <div
                                        v-if="workspace.projects.length > 0"
                                        class="flex -space-x-1"
                                    >
                                        <div
                                            v-for="project in workspace.projects.slice(0, 3)"
                                            :key="project.name"
                                            class="w-6 h-6 rounded-full bg-zinc-700 border border-zinc-600 flex items-center justify-center"
                                            :title="project.name"
                                        >
                                            <FolderGit2 class="w-3 h-3 text-zinc-400" />
                                        </div>
                                        <div
                                            v-if="workspace.projects.length > 3"
                                            class="w-6 h-6 rounded-full bg-zinc-700 border border-zinc-600 flex items-center justify-center text-xs text-zinc-400"
                                        >
                                            +{{ workspace.projects.length - 3 }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right rounded-r-lg">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        @click="openInTerminal(workspace)"
                                        class="btn btn-plain py-1 px-2 text-xs"
                                        title="Open in Terminal"
                                    >
                                        <Terminal class="w-3.5 h-3.5" />
                                    </button>
                                    <button
                                        v-if="workspace.has_workspace_file"
                                        @click="openInEditor(workspace)"
                                        class="btn btn-plain py-1 px-2 text-xs"
                                        :title="`Open in ${editor.name}`"
                                    >
                                        <EditorIcon :editor="editor.scheme" class="w-3.5 h-3.5" />
                                    </button>
                                    <Link
                                        :href="`/environments/${environment.id}/workspaces/${workspace.name}`"
                                        class="btn btn-outline py-1 px-2 text-xs"
                                    >
                                        Manage
                                    </Link>
                                    <button
                                        @click="confirmDelete(workspace.name)"
                                        class="btn btn-plain py-1 px-2 text-xs text-red-400 hover:text-red-300"
                                        :disabled="deletingWorkspace === workspace.name"
                                    >
                                        <Loader2 v-if="deletingWorkspace === workspace.name" class="w-3.5 h-3.5 animate-spin" />
                                        <Trash2 v-else class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <Modal :show="showDeleteModal" title="Delete Workspace" @close="showDeleteModal = false">
        <div class="p-6">
            <p class="text-zinc-400 mb-6">
                Are you sure you want to delete the workspace "{{ workspaceToDelete }}"?
                This will remove the workspace directory and symlinks, but won't delete the actual projects.
            </p>
            <div class="flex justify-end gap-3">
                <button
                    @click="showDeleteModal = false"
                    class="btn btn-plain"
                >
                    Cancel
                </button>
                <button
                    @click="deleteWorkspace"
                    class="btn bg-red-500 hover:bg-red-600 text-white"
                >
                    Delete Workspace
                </button>
            </div>
        </div>
    </Modal>
</template>
