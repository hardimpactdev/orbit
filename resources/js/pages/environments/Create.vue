<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { ChevronLeft, Loader2, CheckCircle, AlertCircle, Server } from 'lucide-vue-next';

interface SshKey {
    id: number;
    name: string;
    public_key: string;
    key_type: string;
    is_default: boolean;
}

interface SshKeyInfo {
    content: string;
    type: string;
}

interface ServerCheckResult {
    has_orbit: boolean;
    has_orbit_user: boolean;
    orbit_running: boolean;
    can_connect: boolean;
    connected_as: string | null;
    error: string | null;
    status?: {
        status: string;
        sites?: any[];
    };
}

const props = defineProps<{
    currentUser: string;
    sshKeys: SshKey[];
    availableSshKeys: Record<string, SshKeyInfo>;
    hasLocalEnvironment: boolean;
}>();

// Environment type toggle
const environmentType = ref<'local' | 'external'>(props.hasLocalEnvironment ? 'external' : 'local');

// Get the default key from database
const defaultKey = computed(() => {
    const defaultSshKey = props.sshKeys.find(k => k.is_default);
    return defaultSshKey?.public_key || props.sshKeys[0]?.public_key || '';
});

// Local form
const localForm = useForm({
    name: 'Local',
    host: 'localhost',
    user: props.currentUser,
    port: 22,
    is_local: true,
});

// External form
const externalForm = useForm({
    name: '',
    host: '',
    user: 'root',
    ssh_public_key: defaultKey.value,
});

// Check if we have any keys available in dropdowns
const hasKeyOptions = computed(() => props.sshKeys.length > 0 || Object.keys(props.availableSshKeys).length > 0);

// Track the selected key in the dropdown separately
const selectedKeyValue = ref(defaultKey.value);

// Whether to show the custom key textarea
const showCustomKeyInput = computed(() => {
    if (!hasKeyOptions.value) return true;
    if (selectedKeyValue.value === 'custom') return true;
    return false;
});

// Sync selectedKeyValue with form when it changes
watch(selectedKeyValue, (newValue) => {
    if (newValue && newValue !== 'custom') {
        externalForm.ssh_public_key = newValue;
    } else if (newValue === 'custom') {
        externalForm.ssh_public_key = '';
    }
});

// Server check state
const isChecking = ref(false);
const checkResult = ref<ServerCheckResult | null>(null);
const hasChecked = ref(false);

// Reset check state when host or user changes
watch([() => externalForm.host, () => externalForm.user], () => {
    checkResult.value = null;
    hasChecked.value = false;
});

const checkServer = async () => {
    if (!externalForm.host) return;

    isChecking.value = true;
    checkResult.value = null;

    try {
        const response = await fetch('/provision/check-server', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                host: externalForm.host,
                user: externalForm.user,
            }),
        });

        checkResult.value = await response.json();
        hasChecked.value = true;
    } catch (error) {
        checkResult.value = {
            has_orbit: false,
            has_orbit_user: false,
            orbit_running: false,
            can_connect: false,
            connected_as: null,
            error: 'Failed to check server connection',
        };
        hasChecked.value = true;
    } finally {
        isChecking.value = false;
    }
};

const addWithoutProvisioning = () => {
    router.post('/servers', {
        name: externalForm.name,
        host: externalForm.host,
        user: checkResult.value?.connected_as === 'launchpad' ? 'launchpad' : externalForm.user,
        port: 22,
        is_local: false,
    });
};

const submitLocal = () => {
    localForm.post('/servers');
};

const submitExternal = () => {
    externalForm.post('/provision');
};
</script>

<template>
    <Head title="Add Environment" />

    <div>
        <div class="mb-6">
            <Link href="/servers" class="text-zinc-400 hover:text-white flex items-center transition-colors text-sm">
                <ChevronLeft class="w-4 h-4 mr-1" />
                Back to Environments
            </Link>
        </div>

        <Heading title="Add Environment" />
        <p class="text-zinc-400 mb-8 mt-2">
            Add a local or external environment to manage development sites.
        </p>

        <!-- Environment Type Toggle -->
        <div class="mb-8">
            <div class="inline-flex rounded-lg bg-zinc-800 p-1">
                <button
                    type="button"
                    @click="environmentType = 'local'"
                    :disabled="hasLocalEnvironment"
                    class="px-4 py-1.5 text-sm font-medium rounded-md transition-colors"
                    :class="environmentType === 'local'
                        ? 'bg-zinc-600 text-white'
                        : hasLocalEnvironment
                            ? 'text-zinc-600 cursor-not-allowed'
                            : 'text-zinc-400 hover:text-white'"
                >
                    Local
                    <span v-if="hasLocalEnvironment" class="ml-1 text-xs">(configured)</span>
                </button>
                <button
                    type="button"
                    @click="environmentType = 'external'"
                    class="px-4 py-1.5 text-sm font-medium rounded-md transition-colors"
                    :class="environmentType === 'external'
                        ? 'bg-zinc-600 text-white'
                        : 'text-zinc-400 hover:text-white'"
                >
                    External
                </button>
            </div>
        </div>

        <!-- Local Environment Form -->
        <form v-if="environmentType === 'local'" @submit.prevent="submitLocal" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <label for="local-name" class="block text-sm font-medium text-zinc-400 mb-2">
                        Environment Name
                    </label>
                    <input
                        v-model="localForm.name"
                        type="text"
                        id="local-name"
                        required
                        placeholder="Local"
                        class="w-full"
                    />
                    <p v-if="localForm.errors.name" class="mt-2 text-sm text-red-400">{{ localForm.errors.name }}</p>
                </div>
            </div>

            <div class="mt-8 flex gap-3">
                <button
                    type="submit"
                    :disabled="localForm.processing"
                    class="btn btn-secondary disabled:opacity-50"
                >
                    Add
                </button>
                <Link
                    href="/servers"
                    class="btn btn-plain"
                >
                    Cancel
                </Link>
            </div>
        </form>

        <!-- External Environment Form -->
        <form v-else @submit.prevent="submitExternal" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <label for="external-name" class="block text-sm font-medium text-zinc-400 mb-2">
                        Environment Name
                    </label>
                    <input
                        v-model="externalForm.name"
                        type="text"
                        id="external-name"
                        required
                        placeholder="Production"
                        class="w-full"
                    />
                    <p v-if="externalForm.errors.name" class="mt-2 text-sm text-red-400">{{ externalForm.errors.name }}</p>
                </div>

                <div>
                    <label for="host" class="block text-sm font-medium text-zinc-400 mb-2">
                        Host IP Address
                    </label>
                    <input
                        v-model="externalForm.host"
                        type="text"
                        id="host"
                        required
                        placeholder="192.168.1.100"
                        class="w-full"
                    />
                    <p v-if="externalForm.errors.host" class="mt-2 text-sm text-red-400">{{ externalForm.errors.host }}</p>
                </div>

                <div>
                    <label for="user" class="block text-sm font-medium text-zinc-400 mb-2">
                        SSH User
                    </label>
                    <input
                        v-model="externalForm.user"
                        type="text"
                        id="user"
                        required
                        placeholder="root"
                        class="w-full"
                    />
                    <p class="mt-2 text-sm text-zinc-500">
                        User must have sudo privileges for provisioning
                    </p>
                    <p v-if="externalForm.errors.user" class="mt-2 text-sm text-red-400">{{ externalForm.errors.user }}</p>
                </div>

                <!-- Check Server Button -->
                <div v-if="externalForm.host && !hasChecked">
                    <button
                        type="button"
                        @click="checkServer"
                        :disabled="isChecking"
                        class="btn btn-outline w-full"
                    >
                        <Loader2 v-if="isChecking" class="w-4 h-4 animate-spin" />
                        <Server v-else class="w-4 h-4" />
                        {{ isChecking ? 'Checking server...' : 'Check Server' }}
                    </button>
                </div>

                <!-- Check Result: Orbit Already Configured -->
                <div v-if="checkResult?.has_orbit" class="p-4 bg-lime-400/10 border border-lime-400/20 rounded-lg">
                    <div class="flex items-start">
                        <CheckCircle class="w-5 h-5 text-lime-400 mr-3 mt-0.5 flex-shrink-0" />
                        <div class="flex-1">
                            <p class="text-sm font-medium text-lime-400">
                                Orbit is already configured on this server!
                            </p>
                            <p class="mt-1 text-sm text-zinc-400">
                                Status: {{ checkResult.orbit_running ? 'Running' : 'Not running' }}
                                <span v-if="checkResult.status?.sites?.length"> · {{ checkResult.status.sites.length }} site(s)</span>
                            </p>
                            <p class="mt-2 text-sm text-zinc-500">
                                You can add this environment without re-provisioning.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Check Result: Can Connect but No Orbit -->
                <div v-else-if="checkResult?.can_connect && !checkResult?.has_orbit" class="p-4 bg-blue-400/10 border border-blue-400/20 rounded-lg">
                    <div class="flex items-start">
                        <CheckCircle class="w-5 h-5 text-blue-400 mr-3 mt-0.5 flex-shrink-0" />
                        <div class="flex-1">
                            <p class="text-sm font-medium text-blue-400">
                                Connected successfully as {{ checkResult.connected_as }}
                            </p>
                            <p class="mt-1 text-sm text-zinc-400">
                                Orbit is not installed on this server. Provisioning will set it up.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Check Result: Cannot Connect -->
                <div v-else-if="checkResult?.error" class="p-4 bg-red-400/10 border border-red-400/20 rounded-lg">
                    <div class="flex items-start">
                        <AlertCircle class="w-5 h-5 text-red-400 mr-3 mt-0.5 flex-shrink-0" />
                        <div class="flex-1">
                            <p class="text-sm font-medium text-red-400">
                                Connection Failed
                            </p>
                            <p class="mt-1 text-sm text-zinc-400">
                                {{ checkResult.error }}
                            </p>
                            <button
                                type="button"
                                @click="hasChecked = false"
                                class="mt-2 text-sm text-zinc-400 hover:text-white transition-colors"
                            >
                                Try again
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="!checkResult?.has_orbit">
                    <label for="ssh_public_key" class="block text-sm font-medium text-zinc-400 mb-2">
                        SSH Public Key
                    </label>
                    <select
                        v-if="hasKeyOptions"
                        v-model="selectedKeyValue"
                        class="w-full"
                    >
                        <option value="">Select a key...</option>
                        <optgroup v-if="sshKeys.length > 0" label="Saved Keys">
                            <option
                                v-for="key in sshKeys"
                                :key="key.id"
                                :value="key.public_key"
                            >
                                {{ key.name }} ({{ key.key_type }}){{ key.is_default ? ' ★' : '' }}
                            </option>
                        </optgroup>
                        <optgroup v-if="Object.keys(availableSshKeys).length > 0" label="Import from ~/.ssh/">
                            <option
                                v-for="(keyInfo, filename) in availableSshKeys"
                                :key="filename"
                                :value="keyInfo.content"
                            >
                                {{ filename }} ({{ keyInfo.type }})
                            </option>
                        </optgroup>
                        <option value="custom">Enter custom key...</option>
                    </select>
                    <textarea
                        v-if="showCustomKeyInput"
                        v-model="externalForm.ssh_public_key"
                        id="ssh_public_key"
                        rows="3"
                        required
                        placeholder="ssh-rsa AAAA..."
                        class="w-full mt-2 font-mono text-sm"
                    />
                    <p class="mt-2 text-sm text-zinc-500">
                        This key will be added to the launchpad user on the remote machine
                    </p>
                    <p v-if="externalForm.errors.ssh_public_key" class="mt-2 text-sm text-red-400">{{ externalForm.errors.ssh_public_key }}</p>
                </div>
            </div>

            <!-- Warning for provisioning -->
            <div v-if="!checkResult?.has_orbit" class="mt-8 p-4 bg-yellow-400/10 border border-yellow-400/20 rounded-lg">
                <p class="text-sm text-yellow-400">
                    <strong>Note:</strong> We'll first check if Orbit is already installed. If not, provisioning will make these changes:
                </p>
                <ul class="mt-2 text-sm text-zinc-400 list-disc list-inside space-y-1">
                    <li>Create a <code class="bg-zinc-800 px-1 rounded text-zinc-300">launchpad</code> user with sudo access</li>
                    <li>Disable SSH password authentication</li>
                    <li>Disable root SSH login</li>
                    <li>Install Docker</li>
                    <li>Install and initialize Orbit</li>
                </ul>
            </div>

            <div class="mt-8 flex gap-3">
                <!-- Show different buttons based on check result -->
                <template v-if="checkResult?.has_orbit">
                    <button
                        type="button"
                        @click="addWithoutProvisioning"
                        :disabled="externalForm.processing || !externalForm.name"
                        class="btn btn-secondary disabled:opacity-50"
                    >
                        <Loader2 v-if="externalForm.processing" class="w-4 h-4 animate-spin" />
                        Add
                    </button>
                </template>
                <template v-else>
                    <button
                        type="submit"
                        :disabled="externalForm.processing"
                        class="btn btn-secondary disabled:opacity-50"
                    >
                        <Loader2 v-if="externalForm.processing" class="w-4 h-4 animate-spin" />
                        {{ externalForm.processing ? 'Setting up...' : 'Provision' }}
                    </button>
                </template>
                <Link
                    href="/servers"
                    class="btn btn-plain"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>
