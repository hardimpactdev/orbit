<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Key, Copy, Star, Pencil, Trash2, FileCode2, ExternalLink } from 'lucide-vue-next';
import Modal from '@/components/Modal.vue';

interface Editor {
    scheme: string;
    name: string;
}

interface SshKey {
    id: number;
    name: string;
    public_key: string;
    key_type: string;
    is_default: boolean;
}

interface AvailableKey {
    content: string;
    type: string;
}

interface TemplateFavorite {
    id: number;
    repo_url: string;
    display_name: string;
    usage_count: number;
    last_used_at: string | null;
}

const props = defineProps<{
    editor: Editor;
    editorOptions: Record<string, string>;
    terminal: string;
    terminalOptions: Record<string, string>;
    sshKeys: SshKey[];
    availableSshKeys: Record<string, AvailableKey>;
    templateFavorites: TemplateFavorite[];
    notificationsEnabled: boolean;
    menuBarEnabled: boolean;
}>();

// Editor/Terminal form
const editorForm = useForm({
    editor: props.editor.scheme,
    terminal: props.terminal,
});

const saveEditor = () => {
    editorForm.post('/settings');
};

// SSH Key modal
const showKeyModal = ref(false);
const editingKey = ref<SshKey | null>(null);

const keyForm = useForm({
    name: '',
    public_key: '',
});

const openAddModal = () => {
    editingKey.value = null;
    keyForm.reset();
    showKeyModal.value = true;
};

const openEditModal = (key: SshKey) => {
    editingKey.value = key;
    keyForm.name = key.name;
    keyForm.public_key = key.public_key;
    showKeyModal.value = true;
};

const closeModal = () => {
    showKeyModal.value = false;
    editingKey.value = null;
    keyForm.reset();
};

const saveKey = () => {
    if (editingKey.value) {
        keyForm.put(`/ssh-keys/${editingKey.value.id}`, {
            onSuccess: closeModal,
        });
    } else {
        keyForm.post('/ssh-keys', {
            onSuccess: closeModal,
        });
    }
};

const deleteKey = (key: SshKey) => {
    if (confirm('Delete this SSH key?')) {
        router.delete(`/ssh-keys/${key.id}`);
    }
};

const setDefaultKey = (key: SshKey) => {
    router.post(`/ssh-keys/${key.id}/default`);
};

const copyKey = async (key: SshKey) => {
    await navigator.clipboard.writeText(key.public_key);
};

const importKey = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    const option = select.selectedOptions[0];
    if (option.value) {
        keyForm.public_key = option.value;
        const keyName = option.dataset.name;
        if (keyName && !keyForm.name) {
            keyForm.name = keyName;
        }
    }
};

const truncateKey = (key: string, length = 80) => {
    return key.length > length ? key.substring(0, length) + '...' : key;
};

// Template Favorites modal
const showTemplateModal = ref(false);
const editingTemplate = ref<TemplateFavorite | null>(null);

const templateForm = useForm({
    repo_url: '',
    display_name: '',
});

const openAddTemplateModal = () => {
    editingTemplate.value = null;
    templateForm.reset();
    showTemplateModal.value = true;
};

const openEditTemplateModal = (template: TemplateFavorite) => {
    editingTemplate.value = template;
    templateForm.repo_url = template.repo_url;
    templateForm.display_name = template.display_name;
    showTemplateModal.value = true;
};

const closeTemplateModal = () => {
    showTemplateModal.value = false;
    editingTemplate.value = null;
    templateForm.reset();
};

const saveTemplate = () => {
    if (editingTemplate.value) {
        templateForm.put(`/template-favorites/${editingTemplate.value.id}`, {
            onSuccess: closeTemplateModal,
        });
    } else {
        templateForm.post('/template-favorites', {
            onSuccess: closeTemplateModal,
        });
    }
};

const deleteTemplate = (template: TemplateFavorite) => {
    if (confirm('Delete this template?')) {
        router.delete(`/template-favorites/${template.id}`);
    }
};

const extractRepoName = (url: string): string => {
    const match = url.match(/(?:github\.com\/)?([^\/]+)\/([^\/]+)/);
    return match ? match[2] : url;
};

const onRepoUrlChange = () => {
    if (!editingTemplate.value && templateForm.repo_url && !templateForm.display_name) {
        templateForm.display_name = extractRepoName(templateForm.repo_url);
    }
};

const openGitHub = (url: string) => {
    const fullUrl = url.startsWith('http') ? url : `https://github.com/${url}`;
    window.open(fullUrl, '_blank');
};

// Notification toggle
const notificationForm = useForm({
    enabled: props.notificationsEnabled,
});

const toggleNotifications = () => {
    notificationForm.enabled = !notificationForm.enabled;
    notificationForm.post('/settings/notifications');
};

// Menu bar toggle
const menuBarForm = useForm({
    enabled: props.menuBarEnabled,
});

const toggleMenuBar = () => {
    menuBarForm.enabled = !menuBarForm.enabled;
    menuBarForm.post('/settings/menu-bar');
};
</script>

<template>
    <Head title="Settings" />

    <form @submit.prevent="saveEditor" class="mx-auto max-w-4xl">
        <h1 class="text-2xl font-semibold text-white sm:text-xl">Settings</h1>
        <hr class="mt-6 mb-10 border-t border-white/10" />

        <!-- Code Editor -->
        <section class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-white">Code Editor</h2>
                <p class="text-sm text-zinc-400">Select your preferred editor for opening remote projects.</p>
            </div>
            <div>
                <select v-model="editorForm.editor" class="w-full">
                    <option v-for="(name, scheme) in editorOptions" :key="scheme" :value="scheme">
                        {{ name }}
                    </option>
                </select>
            </div>
        </section>

        <hr class="my-10 border-t border-white/5" />

        <!-- Terminal -->
        <section class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-white">Terminal</h2>
                <p class="text-sm text-zinc-400">Select your preferred terminal for SSH connections.</p>
            </div>
            <div>
                <select v-model="editorForm.terminal" class="w-full">
                    <option v-for="(name, key) in terminalOptions" :key="key" :value="key">
                        {{ name }}
                    </option>
                </select>
            </div>
        </section>

        <hr class="my-10 border-t border-white/5" />

        <!-- Desktop Notifications -->
        <section class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-white">Desktop Notifications</h2>
                <p class="text-sm text-zinc-400">Show system notifications for project events.</p>
            </div>
            <div class="flex items-center">
                <button
                    type="button"
                    @click="toggleNotifications"
                    :disabled="notificationForm.processing"
                    role="switch"
                    :aria-checked="notificationForm.enabled"
                    :class="[
                        'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-lime-500 focus:ring-offset-2 focus:ring-offset-zinc-900',
                        notificationForm.enabled ? 'bg-lime-500' : 'bg-zinc-700'
                    ]"
                >
                    <span
                        :class="[
                            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                            notificationForm.enabled ? 'translate-x-5' : 'translate-x-0'
                        ]"
                    />
                </button>
            </div>
        </section>

        <hr class="my-10 border-t border-white/5" />

        <!-- Menu Bar -->
        <section class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-white">Menu Bar Icon</h2>
                <p class="text-sm text-zinc-400">Show Orbit in the system menu bar for quick access.</p>
            </div>
            <div class="flex items-center">
                <button
                    type="button"
                    @click="toggleMenuBar"
                    :disabled="menuBarForm.processing"
                    role="switch"
                    :aria-checked="menuBarForm.enabled"
                    :class="[
                        'relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-lime-500 focus:ring-offset-2 focus:ring-offset-zinc-900',
                        menuBarForm.enabled ? 'bg-lime-500' : 'bg-zinc-700'
                    ]"
                >
                    <span
                        :class="[
                            'pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                            menuBarForm.enabled ? 'translate-x-5' : 'translate-x-0'
                        ]"
                    />
                </button>
            </div>
        </section>

        <hr class="my-10 border-t border-white/5" />

        <!-- SSH Keys -->
        <section class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-white">SSH Public Keys</h2>
                <p class="text-sm text-zinc-400">Manage SSH keys for environment provisioning.</p>
            </div>
            <div class="space-y-4">
                <!-- Empty State -->
                <div v-if="sshKeys.length === 0" class="text-center py-6 text-zinc-500">
                    <Key class="w-8 h-8 mx-auto mb-2 text-zinc-600" />
                    <p class="text-sm">No SSH keys configured.</p>
                </div>

                <!-- Keys List -->
                <div v-else class="space-y-3">
                    <div
                        v-for="key in sshKeys"
                        :key="key.id"
                        class="flex items-center justify-between rounded-lg border border-white/10 bg-white/5 px-4 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">{{ key.name }}</span>
                                <span v-if="key.is_default" class="badge badge-lime">Default</span>
                            </div>
                            <p class="mt-0.5 truncate font-mono text-xs text-zinc-500">{{ truncateKey(key.public_key, 50) }}</p>
                        </div>
                        <div class="ml-4 flex items-center gap-1">
                            <button type="button" @click="copyKey(key)" class="p-1.5 text-zinc-500 hover:text-white rounded hover:bg-white/5" title="Copy">
                                <Copy class="w-4 h-4" />
                            </button>
                            <button v-if="!key.is_default" type="button" @click="setDefaultKey(key)" class="p-1.5 text-zinc-500 hover:text-lime-400 rounded hover:bg-white/5" title="Set default">
                                <Star class="w-4 h-4" />
                            </button>
                            <button type="button" @click="openEditModal(key)" class="p-1.5 text-zinc-500 hover:text-white rounded hover:bg-white/5" title="Edit">
                                <Pencil class="w-4 h-4" />
                            </button>
                            <button type="button" @click="deleteKey(key)" class="p-1.5 text-zinc-500 hover:text-red-400 rounded hover:bg-white/5" title="Delete">
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <button type="button" @click="openAddModal" class="btn btn-outline">
                    Add SSH Key
                </button>
            </div>
        </section>

        <hr class="my-10 border-t border-white/5" />

        <!-- Template Favorites -->
        <section class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-white">Template Favorites</h2>
                <p class="text-sm text-zinc-400">Manage your favorite project templates.</p>
            </div>
            <div class="space-y-4">
                <!-- Empty State -->
                <div v-if="templateFavorites.length === 0" class="text-center py-6 text-zinc-500">
                    <FileCode2 class="w-8 h-8 mx-auto mb-2 text-zinc-600" />
                    <p class="text-sm">No template favorites yet.</p>
                </div>

                <!-- Templates List -->
                <div v-else class="space-y-3">
                    <div
                        v-for="template in templateFavorites"
                        :key="template.id"
                        class="flex items-center justify-between rounded-lg border border-white/10 bg-white/5 px-4 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">{{ template.display_name }}</span>
                                <span v-if="template.usage_count > 0" class="badge badge-zinc">Used {{ template.usage_count }}x</span>
                            </div>
                            <p class="mt-0.5 font-mono text-xs text-zinc-500">{{ template.repo_url }}</p>
                        </div>
                        <div class="ml-4 flex items-center gap-1">
                            <button type="button" @click="openGitHub(template.repo_url)" class="p-1.5 text-zinc-500 hover:text-white rounded hover:bg-white/5" title="Open on GitHub">
                                <ExternalLink class="w-4 h-4" />
                            </button>
                            <button type="button" @click="openEditTemplateModal(template)" class="p-1.5 text-zinc-500 hover:text-white rounded hover:bg-white/5" title="Edit">
                                <Pencil class="w-4 h-4" />
                            </button>
                            <button type="button" @click="deleteTemplate(template)" class="p-1.5 text-zinc-500 hover:text-red-400 rounded hover:bg-white/5" title="Delete">
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <button type="button" @click="openAddTemplateModal" class="btn btn-outline">
                    Add Template
                </button>
            </div>
        </section>

        <hr class="my-10 border-t border-white/5" />

        <!-- Submit -->
        <div class="flex justify-end gap-4">
            <button type="reset" class="btn btn-plain">Reset</button>
            <button type="submit" :disabled="editorForm.processing" class="btn btn-secondary">Save changes</button>
        </div>
    </form>

    <!-- Add/Edit Key Modal -->
    <Modal :show="showKeyModal" :title="editingKey ? 'Edit SSH Key' : 'Add SSH Key'" @close="closeModal">
        <form @submit.prevent="saveKey">
            <div class="p-6 space-y-4">
                <div>
                    <label for="keyName" class="block text-sm font-medium text-zinc-400 mb-1.5">Name</label>
                    <input v-model="keyForm.name" type="text" id="keyName" required placeholder="e.g., MacBook Pro" class="w-full" />
                </div>

                <div v-if="Object.keys(availableSshKeys).length > 0 && !editingKey">
                    <label class="block text-sm font-medium text-zinc-400 mb-1.5">Import from ~/.ssh/</label>
                    <select @change="importKey" class="w-full">
                        <option value="">Select a key to import...</option>
                        <option v-for="(keyInfo, filename) in availableSshKeys" :key="filename" :value="keyInfo.content" :data-name="String(filename).replace('.pub', '')">
                            {{ filename }} ({{ keyInfo.type }})
                        </option>
                    </select>
                </div>

                <div>
                    <label for="keyPublicKey" class="block text-sm font-medium text-zinc-400 mb-1.5">Public Key</label>
                    <textarea v-model="keyForm.public_key" id="keyPublicKey" rows="4" required placeholder="ssh-ed25519 AAAA..." class="w-full font-mono text-sm" />
                </div>
            </div>

            <div class="flex justify-end gap-4 px-6 py-4 border-t border-white/5">
                <button type="button" @click="closeModal" class="btn btn-plain">Cancel</button>
                <button type="submit" :disabled="keyForm.processing" class="btn btn-secondary">Save</button>
            </div>
        </form>
    </Modal>

    <!-- Add/Edit Template Modal -->
    <Modal :show="showTemplateModal" :title="editingTemplate ? 'Edit Template' : 'Add Template'" @close="closeTemplateModal">
        <form @submit.prevent="saveTemplate">
            <div class="p-6 space-y-4">
                <div v-if="!editingTemplate">
                    <label for="templateRepoUrl" class="block text-sm font-medium text-zinc-400 mb-1.5">Repository URL</label>
                    <input v-model="templateForm.repo_url" @blur="onRepoUrlChange" type="text" id="templateRepoUrl" required placeholder="owner/repo or https://github.com/owner/repo" class="w-full" />
                </div>
                <div v-else class="text-sm text-zinc-400">
                    <span class="font-medium">Repository:</span> {{ editingTemplate.repo_url }}
                </div>

                <div>
                    <label for="templateDisplayName" class="block text-sm font-medium text-zinc-400 mb-1.5">Display Name</label>
                    <input v-model="templateForm.display_name" type="text" id="templateDisplayName" required placeholder="e.g., Laravel, Next.js Starter" class="w-full" />
                </div>
            </div>

            <div class="flex justify-end gap-4 px-6 py-4 border-t border-white/5">
                <button type="button" @click="closeTemplateModal" class="btn btn-plain">Cancel</button>
                <button type="submit" :disabled="templateForm.processing" class="btn btn-secondary">{{ editingTemplate ? 'Update' : 'Add' }}</button>
            </div>
        </form>
    </Modal>
</template>
