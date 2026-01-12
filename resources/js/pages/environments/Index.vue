<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Server, Loader2, AlertCircle, Trash2, Pencil, Eye } from 'lucide-vue-next';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    port: number;
    is_local: boolean;
    status: string | null;
    provisioning_step: number | null;
    provisioning_total_steps: number | null;
    last_connected_at: string | null;
}

const props = defineProps<{
    environments: Environment[];
    hasLocalEnvironment: boolean;
}>();

// TLD tracking
const tldMap = ref<Map<number, string>>(new Map());
const conflictingTlds = ref<Set<string>>(new Set());

const loadTld = async (environmentId: number) => {
    try {
        const response = await fetch(`/environments/${environmentId}/config`);
        const result = await response.json();

        if (result.success && result.data) {
            const tld = result.data.tld || 'test';
            tldMap.value.set(environmentId, tld);
            checkForConflicts();
        }
    } catch (error) {
        console.error(`Failed to load TLD for environment ${environmentId}:`, error);
    }
};

const checkForConflicts = () => {
    const tldCounts = new Map<string, number>();

    tldMap.value.forEach((tld) => {
        tldCounts.set(tld, (tldCounts.get(tld) || 0) + 1);
    });

    const conflicts = new Set<string>();
    tldCounts.forEach((count, tld) => {
        if (count > 1) {
            conflicts.add(tld);
        }
    });
    conflictingTlds.value = conflicts;
};

const getTld = (environmentId: number) => tldMap.value.get(environmentId);
const isConflict = (environmentId: number) => {
    const tld = tldMap.value.get(environmentId);
    return tld ? conflictingTlds.value.has(tld) : false;
};

const getConflictCount = (environmentId: number) => {
    const tld = tldMap.value.get(environmentId);
    if (!tld) return 0;

    let count = 0;
    tldMap.value.forEach((t) => {
        if (t === tld) count++;
    });
    return count;
};

const deleteEnvironment = (environment: Environment) => {
    if (confirm('Are you sure you want to delete this environment?')) {
        router.delete(`/environments/${environment.id}`);
    }
};

const formatLastConnected = (dateString: string | null) => {
    if (!dateString) return 'Never';

    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
    if (diffDays < 30) return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
    return date.toLocaleDateString();
};

onMounted(() => {
    props.environments.forEach((environment) => loadTld(environment.id));
});
</script>

<template>
    <Head title="Environments" />

    <div>
        <div class="flex justify-between items-center mb-8">
            <Heading title="Environments" />
            <Link
                href="/environments/create"
                class="bg-lime-400 hover:bg-lime-300 text-zinc-950 px-4 py-2 rounded-lg text-sm font-medium transition-colors"
            >
                Add environment
            </Link>
        </div>

        <div>
            <table class="table-catalyst">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="pl-6">Host</th>
                        <th class="pl-6">Status</th>
                        <th class="pl-6">Last connected</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="environment in environments" :key="environment.id">
                        <td class="whitespace-nowrap">
                            <div class="flex items-center">
                                <span class="font-medium text-white">{{ environment.name }}</span>
                                <!-- TLD Badge -->
                                <span
                                    v-if="getTld(environment.id)"
                                    class="ml-2 badge font-mono"
                                    :class="isConflict(environment.id) ? 'badge-red' : 'badge-zinc'"
                                    :title="isConflict(environment.id)
                                        ? `Warning: ${getConflictCount(environment.id)} environments use .${getTld(environment.id)} - this may cause DNS conflicts`
                                        : ''"
                                >
                                    .{{ getTld(environment.id) }}
                                </span>
                                <span
                                    v-if="environment.is_local"
                                    class="ml-2 badge badge-lime"
                                >
                                    Local
                                </span>
                            </div>
                        </td>
                        <td class="pl-6 whitespace-nowrap text-zinc-400">
                            <template v-if="environment.is_local">localhost</template>
                            <template v-else>{{ environment.user }}@{{ environment.host }}:{{ environment.port }}</template>
                        </td>
                        <td class="pl-6 whitespace-nowrap">
                            <!-- Provisioning Status -->
                            <span
                                v-if="environment.status === 'provisioning'"
                                class="inline-flex items-center text-sm text-blue-400"
                            >
                                <Loader2 class="w-3.5 h-3.5 mr-2 animate-spin" />
                                Provisioning ({{ environment.provisioning_step ?? 0 }}/{{ environment.provisioning_total_steps ?? 14 }})
                            </span>
                            <!-- Error Status -->
                            <span
                                v-else-if="environment.status === 'error'"
                                class="inline-flex items-center text-sm text-red-400"
                            >
                                <AlertCircle class="w-3.5 h-3.5 mr-2" />
                                Error
                            </span>
                            <!-- Active Status -->
                            <span v-else class="inline-flex items-center text-sm text-lime-400">
                                <span class="w-2 h-2 rounded-full bg-lime-400 mr-2"></span>
                                Active
                            </span>
                        </td>
                        <td class="pl-6 whitespace-nowrap text-zinc-400">
                            {{ formatLastConnected(environment.last_connected_at) }}
                        </td>
                        <td class="whitespace-nowrap">
                            <div class="flex items-center justify-end gap-1">
                                <Link
                                    :href="`/environments/${environment.id}`"
                                    class="text-zinc-500 hover:text-white p-1.5 rounded transition-colors hover:bg-white/5"
                                    title="View"
                                >
                                    <Eye class="w-4 h-4" />
                                </Link>
                                <Link
                                    :href="`/environments/${environment.id}/edit`"
                                    class="text-zinc-500 hover:text-white p-1.5 rounded transition-colors hover:bg-white/5"
                                    title="Edit"
                                >
                                    <Pencil class="w-4 h-4" />
                                </Link>
                                <button
                                    @click="deleteEnvironment(environment)"
                                    class="text-zinc-500 hover:text-red-400 p-1.5 rounded transition-colors hover:bg-white/5"
                                    title="Delete"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    <!-- Empty State -->
                    <tr v-if="environments.length === 0">
                        <td colspan="5" class="py-12 text-center">
                            <Server class="w-12 h-12 mx-auto text-zinc-600 mb-4" />
                            <p class="text-zinc-400 mb-2">No environments configured yet</p>
                            <p class="text-sm text-zinc-500">Add a local or external environment to get started</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
