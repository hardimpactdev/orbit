<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Key, Copy, Star, Pencil, Trash2, FileCode2, ExternalLink } from 'lucide-vue-next';
import Modal from '@/components/Modal.vue';
import { Button, Input, Label, Badge, Switch, Textarea } from '@hardimpactdev/craft-ui';

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
        <section v-if="$page.props.multi_environment" class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-foreground">Code Editor</h2>
                <p class="text-sm text-muted-foreground">
                    Select your preferred editor for opening remote projects.
                </p>
            </div>
            <div>
                <select v-model="editorForm.editor" class="w-full">
                    <option v-for="(name, scheme) in editorOptions" :key="scheme" :value="scheme">
                        {{ name }}
                    </option>
                </select>
            </div>
        </section>

        <hr v-if="$page.props.multi_environment" class="my-10 border-t border-border" />

        <!-- Terminal -->
        <section v-if="$page.props.multi_environment" class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-foreground">Terminal</h2>
                <p class="text-sm text-muted-foreground">
                    Select your preferred terminal for SSH connections.
                </p>
            </div>
            <div>
                <select v-model="editorForm.terminal" class="w-full">
                    <option v-for="(name, key) in terminalOptions" :key="key" :value="key">
                        {{ name }}
                    </option>
                </select>
            </div>
        </section>

        <hr v-if="$page.props.multi_environment" class="my-10 border-t border-border" />

        <!-- Desktop Notifications -->
        <section v-if="$page.props.multi_environment" class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-foreground">Desktop Notifications</h2>
                <p class="text-sm text-muted-foreground">Show system notifications for project events.</p>
            </div>
            <div class="flex items-center">
                <Switch
                    :checked="notificationForm.enabled"
                    @update:checked="toggleNotifications"
                    :disabled="notificationForm.processing"
                />
            </div>
        </section>

        <hr v-if="$page.props.multi_environment" class="my-10 border-t border-border" />

        <!-- Menu Bar -->
        <section v-if="$page.props.multi_environment" class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-foreground">Menu Bar Icon</h2>
                <p class="text-sm text-muted-foreground">
                    Show Orbit in the system menu bar for quick access.
                </p>
            </div>
            <div class="flex items-center">
                <Switch
                    :checked="menuBarForm.enabled"
                    @update:checked="toggleMenuBar"
                    :disabled="menuBarForm.processing"
                />
            </div>
        </section>

        <hr v-if="$page.props.multi_environment" class="my-10 border-t border-border" />

        <!-- SSH Keys -->
        <section v-if="$page.props.multi_environment" class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-foreground">SSH Public Keys</h2>
                <p class="text-sm text-muted-foreground">Manage SSH keys for environment provisioning.</p>
            </div>
            <div class="space-y-4">
                <!-- Empty State -->
                <div v-if="sshKeys.length === 0" class="text-center py-6 text-muted-foreground">
                    <Key class="w-8 h-8 mx-auto mb-2 text-muted-foreground" />
                    <p class="text-sm">No SSH keys configured.</p>
                </div>

                <!-- Keys List -->
                <div v-else class="space-y-3">
                    <div
                        v-for="key in sshKeys"
                        :key="key.id"
                        class="flex items-center justify-between rounded-lg border border-border bg-card px-4 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-foreground">{{ key.name }}</span>
                                <Badge v-if="key.is_default" class="bg-lime-400/10 text-lime-300 border-lime-400/20">Default</Badge>
                            </div>
                            <p class="mt-0.5 truncate font-mono text-xs text-muted-foreground">
                                {{ truncateKey(key.public_key, 50) }}
                            </p>
                        </div>
                        <div class="ml-4 flex items-center gap-1">
                            <Button
                                type="button"
                                @click="copyKey(key)"
                                variant="ghost"
                                size="icon-sm"
                                title="Copy"
                            >
                                <Copy class="w-4 h-4" />
                            </Button>
                            <Button
                                v-if="!key.is_default"
                                type="button"
                                @click="setDefaultKey(key)"
                                variant="ghost"
                                size="icon-sm"
                                class="hover:text-lime-400"
                                title="Set default"
                            >
                                <Star class="w-4 h-4" />
                            </Button>
                            <Button
                                type="button"
                                @click="openEditModal(key)"
                                variant="ghost"
                                size="icon-sm"
                                title="Edit"
                            >
                                <Pencil class="w-4 h-4" />
                            </Button>
                            <Button
                                type="button"
                                @click="deleteKey(key)"
                                variant="ghost"
                                size="icon-sm"
                                class="hover:text-red-400"
                                title="Delete"
                            >
                                <Trash2 class="w-4 h-4" />
                            </Button>
                        </div>
                    </div>
                </div>

                <Button type="button" @click="openAddModal" variant="outline">
                    Add SSH Key
                </Button>
            </div>
        </section>

        <hr class="my-10 border-t border-white/5" />

        <!-- Template Favorites -->
        <section class="grid gap-x-8 gap-y-6 sm:grid-cols-2">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-foreground">Template Favorites</h2>
                <p class="text-sm text-muted-foreground">Manage your favorite project templates.</p>
            </div>
            <div class="space-y-4">
                <!-- Empty State -->
                <div v-if="templateFavorites.length === 0" class="text-center py-6 text-muted-foreground">
                    <FileCode2 class="w-8 h-8 mx-auto mb-2 text-muted-foreground" />
                    <p class="text-sm">No template favorites yet.</p>
                </div>

                <!-- Templates List -->
                <div v-else class="space-y-3">
                    <div
                        v-for="template in templateFavorites"
                        :key="template.id"
                        class="flex items-center justify-between rounded-lg border border-border bg-card px-4 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-foreground">{{
                                    template.display_name
                                }}</span>
                                <Badge v-if="template.usage_count > 0" variant="secondary"
                                    >Used {{ template.usage_count }}x</Badge
                                >
                            </div>
                            <p class="mt-0.5 font-mono text-xs text-muted-foreground">
                                {{ template.repo_url }}
                            </p>
                        </div>
                        <div class="ml-4 flex items-center gap-1">
                            <Button
                                type="button"
                                @click="openGitHub(template.repo_url)"
                                variant="ghost"
                                size="icon-sm"
                                title="Open on GitHub"
                            >
                                <ExternalLink class="w-4 h-4" />
                            </Button>
                            <Button
                                type="button"
                                @click="openEditTemplateModal(template)"
                                variant="ghost"
                                size="icon-sm"
                                title="Edit"
                            >
                                <Pencil class="w-4 h-4" />
                            </Button>
                            <Button
                                type="button"
                                @click="deleteTemplate(template)"
                                variant="ghost"
                                size="icon-sm"
                                class="hover:text-red-400"
                                title="Delete"
                            >
                                <Trash2 class="w-4 h-4" />
                            </Button>
                        </div>
                    </div>
                </div>

                <Button type="button" @click="openAddTemplateModal" variant="outline">
                    Add Template
                </Button>
            </div>
        </section>

        <hr class="my-10 border-t border-border" />

        <!-- Submit -->
        <div class="flex justify-end gap-4">
            <Button type="reset" variant="ghost">Reset</Button>
            <Button type="submit" :disabled="editorForm.processing" variant="secondary">
                Save changes
            </Button>
        </div>
    </form>

    <!-- Add/Edit Key Modal -->
    <Modal
        :show="showKeyModal"
        :title="editingKey ? 'Edit SSH Key' : 'Add SSH Key'"
        @close="closeModal"
    >
        <form @submit.prevent="saveKey">
            <div class="p-6 space-y-4">
                <div>
                    <Label for="keyName" class="text-muted-foreground mb-1.5">Name</Label>
                    <Input
                        v-model="keyForm.name"
                        type="text"
                        id="keyName"
                        required
                        placeholder="e.g., MacBook Pro"
                        class="w-full"
                    />
                </div>

                <div v-if="Object.keys(availableSshKeys).length > 0 && !editingKey">
                    <Label class="text-muted-foreground mb-1.5">Import from ~/.ssh/</Label>
                    <select @change="importKey" class="w-full">
                        <option value="">Select a key to import...</option>
                        <option
                            v-for="(keyInfo, filename) in availableSshKeys"
                            :key="filename"
                            :value="keyInfo.content"
                            :data-name="String(filename).replace('.pub', '')"
                        >
                            {{ filename }} ({{ keyInfo.type }})
                        </option>
                    </select>
                </div>

                <div>
                    <Label for="keyPublicKey" class="text-muted-foreground mb-1.5">Public Key</Label>
                    <Textarea
                        v-model="keyForm.public_key"
                        id="keyPublicKey"
                        rows="4"
                        required
                        placeholder="ssh-ed25519 AAAA..."
                        class="w-full font-mono text-sm"
                    />
                </div>
            </div>

            <div class="flex justify-end gap-4 px-6 py-4 border-t border-border">
                <Button type="button" @click="closeModal" variant="ghost">Cancel</Button>
                <Button type="submit" :disabled="keyForm.processing" variant="secondary">
                    Save
                </Button>
            </div>
        </form>
    </Modal>

    <!-- Add/Edit Template Modal -->
    <Modal
        :show="showTemplateModal"
        :title="editingTemplate ? 'Edit Template' : 'Add Template'"
        @close="closeTemplateModal"
    >
        <form @submit.prevent="saveTemplate">
            <div class="p-6 space-y-4">
                <div v-if="!editingTemplate">
                    <Label for="templateRepoUrl" class="text-muted-foreground mb-1.5">Repository URL</Label>
                    <Input
                        v-model="templateForm.repo_url"
                        @blur="onRepoUrlChange"
                        type="text"
                        id="templateRepoUrl"
                        required
                        placeholder="owner/repo or https://github.com/owner/repo"
                        class="w-full"
                    />
                </div>
                <div v-else class="text-sm text-muted-foreground">
                    <span class="font-medium">Repository:</span> {{ editingTemplate.repo_url }}
                </div>

                <div>
                    <Label for="templateDisplayName" class="text-muted-foreground mb-1.5">Display Name</Label>
                    <Input
                        v-model="templateForm.display_name"
                        type="text"
                        id="templateDisplayName"
                        required
                        placeholder="e.g., Laravel, Next.js Starter"
                        class="w-full"
                    />
                </div>
            </div>

            <div class="flex justify-end gap-4 px-6 py-4 border-t border-border">
                <Button type="button" @click="closeTemplateModal" variant="ghost">
                    Cancel
                </Button>
                <Button type="submit" :disabled="templateForm.processing" variant="secondary">
                    {{ editingTemplate ? 'Update' : 'Add' }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
