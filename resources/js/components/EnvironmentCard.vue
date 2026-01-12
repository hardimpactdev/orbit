<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ExternalLink, Zap } from 'lucide-vue-next';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
    is_default: boolean;
    status?: string;
}

const props = defineProps<{
    environment: Environment;
}>();

const status = ref<'idle' | 'testing' | 'success' | 'error'>('idle');

const testConnection = async () => {
    status.value = 'testing';

    try {
        const response = await fetch(`/environments/${props.environment.id}/test-connection`, {
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
    idle: 'bg-zinc-600',
    testing: 'bg-yellow-400 animate-pulse',
    success: 'bg-lime-400',
    error: 'bg-red-400',
};
</script>

<template>
    <div class="border border-zinc-800 rounded-lg p-4 hover:border-zinc-700 transition-colors">
        <div class="flex justify-between items-start mb-3">
            <div>
                <h3 class="font-semibold text-white">{{ environment.name }}</h3>
                <p class="text-sm text-zinc-500">
                    <template v-if="environment.is_local">Local</template>
                    <template v-else>{{ environment.user }}@{{ environment.host }}</template>
                </p>
            </div>
            <div class="flex items-center space-x-2">
                <span
                    v-if="environment.is_default"
                    class="badge badge-blue"
                >
                    Default
                </span>
                <span
                    class="w-3 h-3 rounded-full transition-colors"
                    :class="statusColor[status]"
                />
            </div>
        </div>

        <div class="flex gap-2">
            <Link
                :href="`/environments/${environment.id}`"
                class="flex-1 text-center py-2 text-sm bg-zinc-800 text-zinc-300 rounded-lg hover:bg-zinc-700 hover:text-white flex items-center justify-center transition-colors"
            >
                <ExternalLink class="w-4 h-4 mr-2" />
                View
            </Link>
            <button
                @click="testConnection"
                :disabled="status === 'testing'"
                class="flex-1 text-center py-2 text-sm bg-zinc-800 text-zinc-300 rounded-lg hover:bg-zinc-700 hover:text-white disabled:opacity-50 flex items-center justify-center transition-colors"
            >
                <Zap class="w-4 h-4 mr-2" />
                Test
            </button>
        </div>
    </div>
</template>
