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

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                title="Workspaces"
                description="Group related projects together for easier management"
            />
            <Button as-child variant="secondary">
                <Link :href="`/environments/${environment.id}/workspaces/create`">
                    <Plus class="w-4 h-4 mr-2" />
                    New Workspace
                </Link>
            </Button>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="border border-zinc-800 rounded-xl p-12 text-center">
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
            <p class="text-zinc-400 mb-6">Create a workspace to group related projects together.</p>
            <Button as-child variant="secondary">
                <Link :href="`/environments/${environment.id}/workspaces/create`">
                    <Plus class="w-4 h-4 mr-2" />
                    Create Your First Workspace
                </Link>
            </Button>
        </div>

        <!-- Workspaces List -->
        <div v-else>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Workspace</TableHead>
                        <TableHead>Projects</TableHead>
                        <TableHead class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="workspace in workspaces"
                        :key="workspace.name"
                    >
                        <TableCell>
                            <Link
                                :href="`/environments/${environment.id}/workspaces/${workspace.name}`"
                                class="flex items-center gap-3 hover:text-lime-400"
                            >
                                <Boxes class="w-4 h-4 text-lime-400" />
                                <span class="font-medium text-white">
                                    {{ workspace.name }}
                                </span>
                            </Link>
                        </TableCell>
                        <TableCell>
                            <div class="flex items-center gap-2">
                                <span class="text-zinc-400">
                                    {{ workspace.project_count }} project{{
                                        workspace.project_count !== 1 ? 's' : ''
                                    }}
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
                        </TableCell>
                        <TableCell class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <Button
                                    @click="openInTerminal(workspace)"
                                    variant="ghost"
                                    size="sm"
                                    title="Open in Terminal"
                                >
                                    <Terminal class="w-3.5 h-3.5" />
                                </Button>
                                <Button
                                    v-if="workspace.has_workspace_file"
                                    @click="openInEditor(workspace)"
                                    variant="ghost"
                                    size="sm"
                                    :title="`Open in ${editor.name}`"
                                >
                                    <EditorIcon :editor="editor.scheme" class="w-3.5 h-3.5" />
                                </Button>
                                <Button as-child variant="outline" size="sm">
                                    <Link :href="`/environments/${environment.id}/workspaces/${workspace.name}`">
                                        Manage
                                    </Link>
                                </Button>
                                <Button
                                    @click="confirmDelete(workspace.name)"
                                    variant="ghost"
                                    size="sm"
                                    class="text-red-400 hover:text-red-300"
                                    :disabled="deletingWorkspace === workspace.name"
                                >
                                    <Loader2
                                        v-if="deletingWorkspace === workspace.name"
                                        class="w-3.5 h-3.5 animate-spin"
                                    />
                                    <Trash2 v-else class="w-3.5 h-3.5" />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
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
