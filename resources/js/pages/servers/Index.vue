<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { Heading } from '@hardimpactdev/liftoff-vue';
import { Monitor, Server, Loader2, AlertCircle, Trash2, Pencil, Eye } from 'lucide-vue-next';

interface Server {
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
    servers: Server[];
    hasLocalEnvironment: boolean;
}>();

// TLD tracking
const tldMap = ref<Map<number, string>>(new Map());
const conflictingTlds = ref<Set<string>>(new Set());

const loadTld = async (serverId: number) => {
    try {
        const response = await fetch(`/servers/${serverId}/config`);
        const result = await response.json();

        if (result.success && result.data) {
            const tld = result.data.tld || 'test';
            tldMap.value.set(serverId, tld);
            checkForConflicts();
        }
    } catch (error) {
        console.error(`Failed to load TLD for server ${serverId}:`, error);
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

const getTld = (serverId: number) => tldMap.value.get(serverId);
const isConflict = (serverId: number) => {
    const tld = tldMap.value.get(serverId);
    return tld ? conflictingTlds.value.has(tld) : false;
};

const getConflictCount = (serverId: number) => {
    const tld = tldMap.value.get(serverId);
    if (!tld) return 0;

    let count = 0;
    tldMap.value.forEach((t) => {
        if (t === tld) count++;
    });
    return count;
};

const deleteServer = (server: Server) => {
    if (confirm('Are you sure you want to delete this environment?')) {
        router.delete(`/servers/${server.id}`);
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
    props.servers.forEach((server) => loadTld(server.id));
});
</script>

<template>
    <Head title="Environments" />

    <div class="p-6">
        <div class="flex justify-between items-center mb-6">
            <Heading title="Environments" />
            <div class="flex space-x-3">
                <Link
                    v-if="!hasLocalEnvironment"
                    href="/servers/create?type=local"
                    class="border border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 px-4 py-2 rounded-lg flex items-center"
                >
                    <Monitor class="w-5 h-5 mr-2" />
                    Add Local
                </Link>
                <Link
                    href="/provision"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center"
                >
                    <Server class="w-5 h-5 mr-2" />
                    Add External
                </Link>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Host</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Connected</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <tr v-for="server in servers" :key="server.id">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <span class="font-medium text-gray-900 dark:text-white">{{ server.name }}</span>
                                <!-- TLD Badge -->
                                <span
                                    v-if="getTld(server.id)"
                                    class="ml-2 px-2 py-0.5 text-xs font-mono rounded-full"
                                    :class="isConflict(server.id)
                                        ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                                        : 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'"
                                    :title="isConflict(server.id)
                                        ? `Warning: ${getConflictCount(server.id)} environments use .${getTld(server.id)} - this may cause DNS conflicts`
                                        : ''"
                                >
                                    .{{ getTld(server.id) }}
                                </span>
                                <span
                                    v-if="server.is_local"
                                    class="ml-2 px-2 py-1 text-xs bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 rounded"
                                >
                                    Local
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                            <template v-if="server.is_local">localhost</template>
                            <template v-else>{{ server.user }}@{{ server.host }}:{{ server.port }}</template>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <!-- Provisioning Status -->
                            <span
                                v-if="server.status === 'provisioning'"
                                class="inline-flex items-center px-2 py-1 text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 rounded"
                            >
                                <Loader2 class="w-3 h-3 mr-1 animate-spin" />
                                Provisioning ({{ server.provisioning_step ?? 0 }}/{{ server.provisioning_total_steps ?? 14 }})
                            </span>
                            <!-- Error Status -->
                            <span
                                v-else-if="server.status === 'error'"
                                class="inline-flex items-center px-2 py-1 text-xs bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200 rounded"
                            >
                                <AlertCircle class="w-3 h-3 mr-1" />
                                Error
                            </span>
                            <!-- Active Status -->
                            <span v-else class="w-3 h-3 inline-block rounded-full bg-gray-300"></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-gray-500 dark:text-gray-400">
                            {{ formatLastConnected(server.last_connected_at) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <Link
                                :href="`/servers/${server.id}`"
                                class="text-blue-600 hover:text-blue-900 dark:hover:text-blue-400 mr-3"
                            >
                                <Eye class="w-4 h-4 inline" />
                            </Link>
                            <Link
                                :href="`/servers/${server.id}/edit`"
                                class="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200 mr-3"
                            >
                                <Pencil class="w-4 h-4 inline" />
                            </Link>
                            <button
                                @click="deleteServer(server)"
                                class="text-red-600 hover:text-red-900 dark:hover:text-red-400"
                            >
                                <Trash2 class="w-4 h-4 inline" />
                            </button>
                        </td>
                    </tr>
                    <!-- Empty State -->
                    <tr v-if="servers.length === 0">
                        <td colspan="5" class="px-6 py-12 text-center">
                            <Server class="w-12 h-12 mx-auto text-gray-400 mb-4" />
                            <p class="text-gray-500 dark:text-gray-400 mb-2">No environments configured yet</p>
                            <p class="text-sm text-gray-400 dark:text-gray-500">Add a local or external environment to get started</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
