<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { ChevronLeft, AlertTriangle } from 'lucide-vue-next';

interface Environment {
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
    server: Environment;
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
        const response = await fetch(`/environments/${props.server.id}/config`);
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
        const response = await fetch('/api/environments/tlds');
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
        const response = await fetch(`/environments/${props.server.id}/config`, {
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
    form.put(`/environments/${props.server.id}`);
};

onMounted(() => {
    loadConfig();
    loadOtherServerTlds();
});
</script>

<template>
    <Head title="Edit Environment" />

    <div>
        <div class="mb-6">
            <Link href="/servers" class="text-zinc-400 hover:text-white flex items-center transition-colors text-sm">
                <ChevronLeft class="w-4 h-4 mr-1" />
                Back to Environments
            </Link>
        </div>

        <Heading title="Edit Environment" />

        <form @submit.prevent="submit" class="mt-8 max-w-lg">
            <div class="space-y-6">
                <div>
                    <label for="name" class="block text-sm font-medium text-zinc-400 mb-2">Name</label>
                    <input
                        v-model="form.name"
                        type="text"
                        id="name"
                        required
                        class="w-full"
                    />
                    <p v-if="form.errors.name" class="mt-2 text-sm text-red-400">{{ form.errors.name }}</p>
                </div>

                <div class="flex items-center">
                    <input
                        v-model="form.is_local"
                        type="checkbox"
                        id="is_local"
                        class="w-4 h-4 rounded border-zinc-600 bg-zinc-800 text-lime-400 focus:ring-lime-400/20 focus:ring-offset-0"
                    />
                    <label for="is_local" class="ml-2 text-sm text-zinc-400">
                        This is a local environment
                    </label>
                </div>

                <!-- Remote fields -->
                <div v-show="!form.is_local" class="space-y-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="host" class="block text-sm font-medium text-zinc-400 mb-2">Host</label>
                            <input
                                v-model="form.host"
                                type="text"
                                id="host"
                                class="w-full"
                            />
                            <p v-if="form.errors.host" class="mt-2 text-sm text-red-400">{{ form.errors.host }}</p>
                        </div>

                        <div>
                            <label for="port" class="block text-sm font-medium text-zinc-400 mb-2">Port</label>
                            <input
                                v-model="form.port"
                                type="number"
                                id="port"
                                class="w-full"
                            />
                            <p v-if="form.errors.port" class="mt-2 text-sm text-red-400">{{ form.errors.port }}</p>
                        </div>
                    </div>

                    <div>
                        <label for="user" class="block text-sm font-medium text-zinc-400 mb-2">SSH User</label>
                        <input
                            v-model="form.user"
                            type="text"
                            id="user"
                            class="w-full"
                        />
                        <p v-if="form.errors.user" class="mt-2 text-sm text-red-400">{{ form.errors.user }}</p>
                    </div>
                </div>

                <!-- TLD Configuration -->
                <div class="pt-6 border-t border-zinc-800">
                    <h3 class="text-sm font-medium text-white mb-4">DNS Configuration</h3>
                    <div>
                        <label for="tld" class="block text-sm font-medium text-zinc-400 mb-2">
                            TLD (Top-Level Domain)
                        </label>
                        <div class="flex items-center">
                            <span class="text-zinc-500 mr-1">.</span>
                            <input
                                v-model="tld"
                                type="text"
                                id="tld"
                                placeholder="test"
                                class="w-32 font-mono"
                            />
                        </div>
                        <p class="mt-2 text-sm text-zinc-500">
                            Sites will be accessible at <span class="font-mono text-zinc-400">sitename.{{ tldPreview }}</span>
                        </p>
                        <p v-if="tldLoading" class="mt-2 text-sm text-zinc-500">
                            Loading current TLD...
                        </p>
                        <p v-if="hasConflict" class="mt-2 text-sm text-amber-400 flex items-center">
                            <AlertTriangle class="w-4 h-4 mr-1" />
                            Another environment is using .{{ tldPreview }} - this may cause DNS conflicts
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-8 flex gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="btn btn-secondary disabled:opacity-50"
                >
                    Save Changes
                </button>
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
