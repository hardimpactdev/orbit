<script setup lang="ts">
import { ref, reactive } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { Heading } from '@hardimpactdev/liftoff-vue';
import { Key, Copy, Star, Pencil, Trash2 } from 'lucide-vue-next';
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

const props = defineProps<{
    editor: Editor;
    editorOptions: Record<string, string>;
    sshKeys: SshKey[];
    availableSshKeys: Record<string, AvailableKey>;
}>();

// Editor form
const editorForm = useForm({
    editor: props.editor.scheme,
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
</script>

<template>
    <Head title="Settings" />

    <div class="p-6 max-w-2xl">
        <Heading title="Settings" />

        <!-- Editor Settings -->
        <form @submit.prevent="saveEditor" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6 mt-6">
            <div class="mb-6">
                <label for="editor" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Code Editor
                </label>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                    Select your preferred editor for opening remote projects via SSH.
                </p>
                <select
                    v-model="editorForm.editor"
                    id="editor"
                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                >
                    <option v-for="(name, scheme) in editorOptions" :key="scheme" :value="scheme">
                        {{ name }}
                    </option>
                </select>
            </div>

            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 mb-6">
                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Requirements</h4>
                <ul class="text-sm text-gray-500 dark:text-gray-400 list-disc list-inside space-y-1">
                    <li>Your editor must have the <strong>Remote - SSH</strong> extension installed</li>
                    <li>SSH keys must be configured for passwordless authentication</li>
                    <li>The editor's URL handler must be registered on your system</li>
                </ul>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="editorForm.processing"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                >
                    Save Settings
                </button>
            </div>
        </form>

        <!-- SSH Keys Section -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white">SSH Public Keys</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Manage SSH keys for environment provisioning.
                    </p>
                </div>
                <button
                    @click="openAddModal"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm"
                >
                    Add Key
                </button>
            </div>

            <!-- Empty State -->
            <div v-if="sshKeys.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
                <Key class="w-12 h-12 mx-auto mb-3 text-gray-300 dark:text-gray-600" />
                <p>No SSH keys configured.</p>
                <p class="text-sm mt-1">Add a key to use when provisioning environments.</p>
            </div>

            <!-- Keys List -->
            <div v-else class="space-y-3">
                <div
                    v-for="key in sshKeys"
                    :key="key.id"
                    class="border border-gray-200 dark:border-gray-600 rounded-lg p-4"
                >
                    <div class="flex justify-between items-start">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-gray-800 dark:text-white">{{ key.name }}</span>
                                <span
                                    v-if="key.is_default"
                                    class="px-2 py-0.5 text-xs bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded"
                                >
                                    Default
                                </span>
                                <span class="text-xs text-gray-400">{{ key.key_type }}</span>
                            </div>
                            <div
                                class="mt-1 font-mono text-xs text-gray-500 dark:text-gray-400 truncate"
                                :title="key.public_key"
                            >
                                {{ truncateKey(key.public_key) }}
                            </div>
                        </div>
                        <div class="flex items-center gap-1 ml-4">
                            <button
                                @click="copyKey(key)"
                                class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                title="Copy"
                            >
                                <Copy class="w-4 h-4" />
                            </button>
                            <button
                                v-if="!key.is_default"
                                @click="setDefaultKey(key)"
                                class="p-1.5 text-gray-400 hover:text-blue-600"
                                title="Set as default"
                            >
                                <Star class="w-4 h-4" />
                            </button>
                            <button
                                @click="openEditModal(key)"
                                class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                                title="Edit"
                            >
                                <Pencil class="w-4 h-4" />
                            </button>
                            <button
                                @click="deleteKey(key)"
                                class="p-1.5 text-gray-400 hover:text-red-600"
                                title="Delete"
                            >
                                <Trash2 class="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Key Modal -->
    <Modal :show="showKeyModal" :title="editingKey ? 'Edit SSH Key' : 'Add SSH Key'" @close="closeModal">
        <form @submit.prevent="saveKey">
            <div class="p-6 space-y-4">
                <div>
                    <label for="keyName" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Name
                    </label>
                    <input
                        v-model="keyForm.name"
                        type="text"
                        id="keyName"
                        required
                        placeholder="e.g., MacBook Pro, Work Laptop"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"
                    />
                </div>

                <div v-if="Object.keys(availableSshKeys).length > 0 && !editingKey">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Import from ~/.ssh/
                    </label>
                    <select
                        @change="importKey"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">-- Select a key to import --</option>
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
                    <label for="keyPublicKey" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Public Key
                    </label>
                    <textarea
                        v-model="keyForm.public_key"
                        id="keyPublicKey"
                        rows="4"
                        required
                        placeholder="ssh-ed25519 AAAA... user@host"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white font-mono text-sm"
                    />
                </div>
            </div>

            <div class="flex justify-end gap-3 px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                <button
                    type="button"
                    @click="closeModal"
                    class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    :disabled="keyForm.processing"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                >
                    Save Key
                </button>
            </div>
        </form>
    </Modal>
</template>
