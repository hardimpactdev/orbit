<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Heading } from '@hardimpactdev/liftoff-vue';
import { ChevronLeft, Loader2 } from 'lucide-vue-next';

interface SshKeyInfo {
    content: string;
    type: string;
}

const props = defineProps<{
    sshPublicKey: string;
    availableSshKeys: Record<string, SshKeyInfo>;
}>();

const form = useForm({
    name: '',
    host: '',
    user: 'root',
    ssh_public_key: props.sshPublicKey || '',
});

const handleKeySelect = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    if (select.value && select.value !== 'custom') {
        form.ssh_public_key = select.value;
    } else if (select.value === 'custom') {
        form.ssh_public_key = '';
    }
};

const submit = () => {
    form.post('/provision');
};
</script>

<template>
    <Head title="Add External Environment" />

    <div class="p-6 max-w-2xl">
        <div class="mb-6">
            <Link href="/servers" class="text-blue-600 hover:text-blue-800 flex items-center">
                <ChevronLeft class="w-4 h-4 mr-1" />
                Back to Environments
            </Link>
        </div>

        <Heading title="Add External Environment" />
        <p class="text-gray-600 dark:text-gray-400 mb-6 mt-2">
            Set up an external environment with the complete Launchpad stack. Requires root SSH access.
        </p>

        <form @submit.prevent="submit" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Environment Name
                    </label>
                    <input
                        v-model="form.name"
                        type="text"
                        id="name"
                        required
                        placeholder="Production"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                    />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label for="host" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Host IP Address
                    </label>
                    <input
                        v-model="form.host"
                        type="text"
                        id="host"
                        required
                        placeholder="192.168.1.100"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                    />
                    <p v-if="form.errors.host" class="mt-1 text-sm text-red-600">{{ form.errors.host }}</p>
                </div>

                <div>
                    <label for="user" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        SSH User
                    </label>
                    <input
                        v-model="form.user"
                        type="text"
                        id="user"
                        required
                        placeholder="root"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                    />
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        User must have sudo privileges for provisioning
                    </p>
                    <p v-if="form.errors.user" class="mt-1 text-sm text-red-600">{{ form.errors.user }}</p>
                </div>

                <div>
                    <label for="ssh_public_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        SSH Public Key
                    </label>
                    <select
                        v-if="Object.keys(availableSshKeys).length > 0"
                        @change="handleKeySelect"
                        class="w-full px-3 py-2 mb-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                    >
                        <option value="">Select a key...</option>
                        <option
                            v-for="(keyInfo, filename) in availableSshKeys"
                            :key="filename"
                            :value="keyInfo.content"
                            :selected="sshPublicKey === keyInfo.content"
                        >
                            {{ filename }} ({{ keyInfo.type }})
                        </option>
                        <option value="custom">Enter custom key...</option>
                    </select>
                    <textarea
                        v-model="form.ssh_public_key"
                        id="ssh_public_key"
                        rows="3"
                        required
                        placeholder="ssh-rsa AAAA..."
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white font-mono text-sm"
                    />
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        This key will be added to the launchpad user on the remote machine
                    </p>
                    <p v-if="form.errors.ssh_public_key" class="mt-1 text-sm text-red-600">{{ form.errors.ssh_public_key }}</p>
                </div>
            </div>

            <div class="mt-6 p-4 bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                <p class="text-sm text-yellow-800 dark:text-yellow-200">
                    <strong>Warning:</strong> This will make the following changes to the remote machine:
                </p>
                <ul class="mt-2 text-sm text-yellow-700 dark:text-yellow-300 list-disc list-inside space-y-1">
                    <li>Create a <code class="bg-yellow-100 dark:bg-yellow-900 px-1 rounded">launchpad</code> user with sudo access</li>
                    <li>Disable SSH password authentication</li>
                    <li>Disable root SSH login</li>
                    <li>Install Docker</li>
                    <li>Install and initialize Launchpad</li>
                </ul>
            </div>

            <div class="mt-6 flex justify-end space-x-3">
                <Link
                    href="/servers"
                    class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"
                >
                    Cancel
                </Link>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 flex items-center"
                >
                    <Loader2 v-if="form.processing" class="w-4 h-4 mr-2 animate-spin" />
                    {{ form.processing ? 'Setting up...' : 'Add External Environment' }}
                </button>
            </div>
        </form>
    </div>
</template>
