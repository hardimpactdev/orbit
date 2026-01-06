<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Heading } from '@hardimpactdev/liftoff-vue';
import { Plus, Server as ServerIcon } from 'lucide-vue-next';
import ServerCard from '@/components/ServerCard.vue';

interface Server {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
    is_default: boolean;
}

defineProps<{
    servers: Server[];
    defaultServer: Server | null;
}>();
</script>

<template>
    <Head title="Dashboard" />

    <div class="p-6">
        <div class="flex justify-between items-center mb-6">
            <Heading title="Dashboard" />
            <Link
                href="/servers/create"
                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center"
            >
                <Plus class="w-5 h-5 mr-2" />
                Add Environment
            </Link>
        </div>

        <!-- Empty State -->
        <div v-if="servers.length === 0" class="bg-white dark:bg-gray-800 rounded-lg shadow p-8 text-center">
            <ServerIcon class="w-16 h-16 mx-auto text-gray-400 mb-4" />
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">
                No environments configured
            </h3>
            <p class="text-gray-500 dark:text-gray-400 mb-4">
                Get started by adding your first environment.
            </p>
            <Link
                href="/servers/create"
                class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg"
            >
                <Plus class="w-5 h-5 mr-2" />
                Add Environment
            </Link>
        </div>

        <!-- Server Cards Grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <ServerCard
                v-for="server in servers"
                :key="server.id"
                :server="server"
            />
        </div>
    </div>
</template>
