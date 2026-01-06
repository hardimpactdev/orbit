<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ExternalLink, Zap } from 'lucide-vue-next';

interface Server {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
    is_default: boolean;
    status?: string;
}

const props = defineProps<{
    server: Server;
}>();

const status = ref<'idle' | 'testing' | 'success' | 'error'>('idle');

const testConnection = async () => {
    status.value = 'testing';

    try {
        const response = await fetch(`/servers/${props.server.id}/test-connection`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                'Accept': 'application/json',
            },
        });

        const result = await response.json();
        status.value = result.success ? 'success' : 'error';
    } catch (error) {
        status.value = 'error';
    }
};

const statusColor = {
    idle: 'bg-gray-300 dark:bg-gray-600',
    testing: 'bg-yellow-400 animate-pulse',
    success: 'bg-green-500',
    error: 'bg-red-500',
};
</script>

<template>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 hover:shadow-lg transition-shadow">
        <div class="flex justify-between items-start mb-3">
            <div>
                <h3 class="font-semibold text-gray-900 dark:text-white">{{ server.name }}</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    <template v-if="server.is_local">Local</template>
                    <template v-else>{{ server.user }}@{{ server.host }}</template>
                </p>
            </div>
            <div class="flex items-center space-x-2">
                <span
                    v-if="server.is_default"
                    class="px-2 py-1 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300 rounded"
                >
                    Default
                </span>
                <span
                    class="w-3 h-3 rounded-full transition-colors"
                    :class="statusColor[status]"
                />
            </div>
        </div>

        <div class="flex space-x-2">
            <Link
                :href="`/servers/${server.id}`"
                class="flex-1 text-center py-2 text-sm bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-200 dark:hover:bg-gray-600 flex items-center justify-center"
            >
                <ExternalLink class="w-4 h-4 mr-2" />
                View
            </Link>
            <button
                @click="testConnection"
                :disabled="status === 'testing'"
                class="flex-1 text-center py-2 text-sm bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-200 rounded hover:bg-blue-200 dark:hover:bg-blue-800 disabled:opacity-50 flex items-center justify-center"
            >
                <Zap class="w-4 h-4 mr-2" />
                Test
            </button>
        </div>
    </div>
</template>
