<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { ChevronLeft, Loader2, CheckCircle, AlertCircle, Server } from 'lucide-vue-next';
import { Button, Input, Label } from '@hardimpactdev/craft-ui';

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
    sshKeys: SshKey[];
    availableSshKeys: Record<string, SshKeyInfo>;
}>();

// Get the default key from database, or empty string
const defaultKey = computed(() => {
    const defaultSshKey = props.sshKeys.find((k) => k.is_default);
    return defaultSshKey?.public_key || props.sshKeys[0]?.public_key || '';
});

const form = useForm({
    name: '',
    host: '',
    user: 'root',
    ssh_public_key: defaultKey.value,
});

// Check if we have any keys available in dropdowns
const hasKeyOptions = computed(
    () => props.sshKeys.length > 0 || Object.keys(props.availableSshKeys).length > 0,
);

// Track the selected key in the dropdown separately
const selectedKeyValue = ref(defaultKey.value);

// Whether to show the custom key textarea
const showCustomKeyInput = computed(() => {
    // Show if no dropdown options exist
    if (!hasKeyOptions.value) return true;
    // Show if "custom" is explicitly selected
    if (selectedKeyValue.value === 'custom') return true;
    // Hide otherwise
    return false;
});

// Sync selectedKeyValue with form when it changes
watch(selectedKeyValue, (newValue) => {
    if (newValue && newValue !== 'custom') {
        form.ssh_public_key = newValue;
    } else if (newValue === 'custom') {
        form.ssh_public_key = '';
    }
});

// Server check state
const isChecking = ref(false);
const checkResult = ref<ServerCheckResult | null>(null);
const hasChecked = ref(false);

// Reset check state when host or user changes
watch([() => form.host, () => form.user], () => {
    checkResult.value = null;
    hasChecked.value = false;
});

const checkServer = async () => {
    if (!form.host) return;

    isChecking.value = true;
    checkResult.value = null;

    try {
        const response = await fetch('/provision/check-server', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                    '',
            },
            body: JSON.stringify({
                host: form.host,
                user: form.user,
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
    // Create server directly without provisioning
    router.post('/servers', {
        name: form.name,
        host: form.host,
        user: checkResult.value?.connected_as === 'launchpad' ? 'launchpad' : form.user,
        port: 22,
        is_local: false,
    });
};

const submit = () => {
    form.post('/provision');
};
</script>

<template>
    <Head title="Add External Environment" />

    <div>
        <div class="mb-6">
            <Link
                href="/servers"
                class="text-zinc-400 hover:text-white flex items-center transition-colors text-sm"
            >
                <ChevronLeft class="w-4 h-4 mr-1" />
                Back to Environments
            </Link>
        </div>

        <Heading title="Add External Environment" />
        <p class="text-zinc-400 mb-8 mt-2">
            Set up an external environment with the complete Orbit stack. Requires root SSH access.
        </p>

        <form @submit.prevent="submit" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <Label for="name" class="text-muted-foreground mb-2">
                        Environment Name
                    </Label>
                    <Input
                        v-model="form.name"
                        type="text"
                        id="name"
                        required
                        placeholder="Production"
                        class="w-full"
                    />
                    <p v-if="form.errors.name" class="mt-2 text-sm text-red-400">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div>
                    <Label for="host" class="text-muted-foreground mb-2">
                        Host IP Address
                    </Label>
                    <Input
                        v-model="form.host"
                        type="text"
                        id="host"
                        required
                        placeholder="192.168.1.100"
                        class="w-full"
                    />
                    <p v-if="form.errors.host" class="mt-2 text-sm text-red-400">
                        {{ form.errors.host }}
                    </p>
                </div>

                <div>
                    <Label for="user" class="text-muted-foreground mb-2">
                        SSH User
                    </Label>
                    <Input
                        v-model="form.user"
                        type="text"
                        id="user"
                        required
                        placeholder="root"
                        class="w-full"
                    />
                    <p class="mt-2 text-sm text-muted-foreground">
                        User must have sudo privileges for provisioning
                    </p>
                    <p v-if="form.errors.user" class="mt-2 text-sm text-red-400">
                        {{ form.errors.user }}
                    </p>
                </div>

                <!-- Check Server Button -->
                <div v-if="form.host && !hasChecked">
                    <Button
                        type="button"
                        @click="checkServer"
                        :disabled="isChecking"
                        variant="outline"
                        class="w-full"
                    >
                        <Loader2 v-if="isChecking" class="w-4 h-4 animate-spin" />
                        <Server v-else class="w-4 h-4" />
                        {{ isChecking ? 'Checking server...' : 'Check Server' }}
                    </Button>
                </div>

                <!-- Check Result: Orbit Already Configured -->
                <div
                    v-if="checkResult?.has_orbit"
                    class="p-4 bg-lime-400/10 border border-lime-400/20 rounded-lg"
                >
                    <div class="flex items-start">
                        <CheckCircle class="w-5 h-5 text-lime-400 mr-3 mt-0.5 flex-shrink-0" />
                        <div class="flex-1">
                            <p class="text-sm font-medium text-lime-400">
                                Orbit is already configured on this server!
                            </p>
                            <p class="mt-1 text-sm text-zinc-400">
                                Status: {{ checkResult.orbit_running ? 'Running' : 'Not running' }}
                                <span v-if="checkResult.status?.sites?.length">
                                    · {{ checkResult.status.sites.length }} site(s)</span
                                >
                            </p>
                            <p class="mt-2 text-sm text-zinc-500">
                                You can add this environment without re-provisioning.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Check Result: Can Connect but No Orbit -->
                <div
                    v-else-if="checkResult?.can_connect && !checkResult?.has_orbit"
                    class="p-4 bg-blue-400/10 border border-blue-400/20 rounded-lg"
                >
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
                <div
                    v-else-if="checkResult?.error"
                    class="p-4 bg-red-400/10 border border-red-400/20 rounded-lg"
                >
                    <div class="flex items-start">
                        <AlertCircle class="w-5 h-5 text-red-400 mr-3 mt-0.5 flex-shrink-0" />
                        <div class="flex-1">
                            <p class="text-sm font-medium text-red-400">Connection Failed</p>
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
                    <Label for="ssh_public_key" class="text-muted-foreground mb-2">
                        SSH Public Key
                    </Label>
                    <select v-if="hasKeyOptions" v-model="selectedKeyValue" class="w-full">
                        <option value="">Select a key...</option>
                        <optgroup v-if="sshKeys.length > 0" label="Saved Keys">
                            <option v-for="key in sshKeys" :key="key.id" :value="key.public_key">
                                {{ key.name }} ({{ key.key_type }}){{ key.is_default ? ' ★' : '' }}
                            </option>
                        </optgroup>
                        <optgroup
                            v-if="Object.keys(availableSshKeys).length > 0"
                            label="Import from ~/.ssh/"
                        >
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
                        v-model="form.ssh_public_key"
                        id="ssh_public_key"
                        rows="3"
                        required
                        placeholder="ssh-rsa AAAA..."
                        class="w-full mt-2 font-mono text-sm"
                    />
                    <p class="mt-2 text-sm text-muted-foreground">
                        This key will be added to the launchpad user on the remote machine
                    </p>
                    <p v-if="form.errors.ssh_public_key" class="mt-2 text-sm text-red-400">
                        {{ form.errors.ssh_public_key }}
                    </p>
                </div>
            </div>

            <!-- Warning for provisioning -->
            <div
                v-if="!checkResult?.has_orbit"
                class="mt-8 p-4 bg-yellow-400/10 border border-yellow-400/20 rounded-lg"
            >
                <p class="text-sm text-yellow-400">
                    <strong>Note:</strong> We'll first check if Orbit is already installed. If not,
                    provisioning will make these changes:
                </p>
                <ul class="mt-2 text-sm text-zinc-400 list-disc list-inside space-y-1">
                    <li>
                        Create a
                        <code class="bg-zinc-800 px-1 rounded text-zinc-300">launchpad</code> user
                        with sudo access
                    </li>
                    <li>Disable SSH password authentication</li>
                    <li>Disable root SSH login</li>
                    <li>Install Docker</li>
                    <li>Install and initialize Orbit</li>
                </ul>
            </div>

            <div class="mt-8 flex gap-3">
                <!-- Show different buttons based on check result -->
                <template v-if="checkResult?.has_orbit">
                    <Button
                        type="button"
                        @click="addWithoutProvisioning"
                        :disabled="form.processing || !form.name"
                        variant="secondary"
                    >
                        <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
                        Add
                    </Button>
                </template>
                <template v-else>
                    <Button
                        type="submit"
                        :disabled="form.processing"
                        variant="secondary"
                    >
                        <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin" />
                        {{ form.processing ? 'Setting up...' : 'Provision' }}
                    </Button>
                </template>
                <Button as-child variant="ghost">
                    <Link href="/servers">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
