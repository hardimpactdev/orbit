<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Heading } from '@hardimpactdev/liftoff-vue';
import { ChevronLeft, AlertTriangle } from 'lucide-vue-next';

interface Server {
    id: number;
    name: string;
    host: string;
    user: string;
    port: number;
    is_local: boolean;
}

interface Config {
    tld: string;
    paths: string[];
    default_php_version: string;
}

const props = defineProps<{
    server: Server;
}>();

const form = useForm({
    name: props.server.name,
    host: props.server.host,
    user: props.server.user,
    port: props.server.port,
    is_local: props.server.is_local,
});

// TLD Configuration
const tld = ref('');
const tldLoading = ref(true);
const currentConfig = ref<Config | null>(null);
const otherServerTlds = ref<Record<number, string>>({});

const tldPreview = computed(() => tld.value || 'test');

const conflictingServers = computed(() => {
    const currentTld = tld.value || 'test';
    return Object.entries(otherServerTlds.value)
        .filter(([id, serverTld]) => serverTld === currentTld && parseInt(id) !== props.server.id)
        .map(([id]) => parseInt(id));
});

const hasConflict = computed(() => conflictingServers.value.length > 0);

const loadConfig = async () => {
    try {
        const response = await fetch(`/servers/${props.server.id}/config`);
        const result = await response.json();

        if (result.success && result.data) {
            currentConfig.value = result.data;
            tld.value = result.data.tld || 'test';
        }
    } catch (error) {
        console.error('Failed to load config:', error);
    } finally {
        tldLoading.value = false;
    }
};

const loadOtherServerTlds = async () => {
    try {
        const response = await fetch('/api/servers/tlds');
        const result = await response.json();
        if (result.success) {
            otherServerTlds.value = result.data || {};
        }
    } catch (error) {
        console.error('Failed to load other server TLDs:', error);
    }
};

const saveTldConfig = async (): Promise<{ success: boolean; error?: string }> => {
    const currentTld = tld.value.trim() || 'test';

    if (!currentConfig.value) {
        return { success: false, error: 'Config not loaded' };
    }

    try {
        const response = await fetch(`/servers/${props.server.id}/config`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                paths: currentConfig.value.paths || [],
                tld: currentTld,
                default_php_version: currentConfig.value.default_php_version || '8.4',
            }),
        });

        if (!response.ok) {
            const text = await response.text();
            try {
                const json = JSON.parse(text);
                return { success: false, error: json.message || 'Request failed' };
            } catch {
                return { success: false, error: `Server error: ${response.status}` };
            }
        }

        return await response.json();
    } catch (error) {
        return { success: false, error: (error as Error).message };
    }
};

const submit = async () => {
    // Save TLD config first
    const tldResult = await saveTldConfig();
    if (!tldResult.success) {
        alert('Failed to save TLD configuration: ' + (tldResult.error || 'Unknown error'));
        return;
    }

    // Then submit the form
    form.put(`/servers/${props.server.id}`);
};

onMounted(() => {
    loadConfig();
    loadOtherServerTlds();
});
</script>

<template>
    <Head title="Edit Environment" />

    <div class="p-6 max-w-2xl">
        <div class="mb-6">
            <Link href="/servers" class="text-blue-600 hover:text-blue-800 flex items-center">
                <ChevronLeft class="w-4 h-4 mr-1" />
                Back to Environments
            </Link>
        </div>

        <Heading title="Edit Environment" class="mb-6" />

        <form @submit.prevent="submit" class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                    <input
                        v-model="form.name"
                        type="text"
                        id="name"
                        required
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                    />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>

                <div class="flex items-center mb-4">
                    <input
                        v-model="form.is_local"
                        type="checkbox"
                        id="is_local"
                        class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                    />
                    <label for="is_local" class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                        This is a local environment
                    </label>
                </div>

                <!-- Remote fields -->
                <div v-show="!form.is_local">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="host" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Host</label>
                            <input
                                v-model="form.host"
                                type="text"
                                id="host"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                            />
                            <p v-if="form.errors.host" class="mt-1 text-sm text-red-600">{{ form.errors.host }}</p>
                        </div>

                        <div>
                            <label for="port" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Port</label>
                            <input
                                v-model="form.port"
                                type="number"
                                id="port"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                            />
                            <p v-if="form.errors.port" class="mt-1 text-sm text-red-600">{{ form.errors.port }}</p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label for="user" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">SSH User</label>
                        <input
                            v-model="form.user"
                            type="text"
                            id="user"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                        />
                        <p v-if="form.errors.user" class="mt-1 text-sm text-red-600">{{ form.errors.user }}</p>
                    </div>
                </div>

                <!-- TLD Configuration -->
                <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">DNS Configuration</h3>
                    <div>
                        <label for="tld" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            TLD (Top-Level Domain)
                        </label>
                        <div class="flex items-center">
                            <span class="text-gray-500 dark:text-gray-400 mr-1">.</span>
                            <input
                                v-model="tld"
                                type="text"
                                id="tld"
                                placeholder="test"
                                class="w-32 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white font-mono"
                            />
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Sites will be accessible at <span class="font-mono">sitename.{{ tldPreview }}</span>
                        </p>
                        <p v-if="tldLoading" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Loading current TLD...
                        </p>
                        <p v-if="hasConflict" class="mt-2 text-xs text-amber-600 dark:text-amber-400 flex items-center">
                            <AlertTriangle class="w-4 h-4 mr-1" />
                            Another environment is using .{{ tldPreview }} - this may cause DNS conflicts
                        </p>
                    </div>
                </div>
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
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
                >
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</template>
