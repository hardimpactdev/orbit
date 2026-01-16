<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Server as ServerIcon } from 'lucide-vue-next';
import EnvironmentCard from '@/components/EnvironmentCard.vue';
import { Button } from '@hardimpactdev/craft-ui';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    is_local: boolean;
    is_default: boolean;
}

defineProps<{
    environments: Environment[];
    defaultEnvironment: Environment | null;
}>();
</script>

<template>
    <Head title="Dashboard" />

    <div>
        <div class="flex justify-between items-center mb-8">
            <Heading title="Dashboard" />
            <Button as-child variant="secondary">
                <Link href="/environments/create">Add</Link>
            </Button>
        </div>

        <!-- Empty State -->
        <div
            v-if="environments.length === 0"
            class="border border-zinc-800 rounded-lg p-8 text-center"
        >
            <ServerIcon class="w-16 h-16 mx-auto text-zinc-600 mb-4" />
            <h3 class="text-lg font-medium text-white mb-2">No environments configured</h3>
            <p class="text-zinc-400 mb-4">Get started by adding your first environment.</p>
            <Button as-child variant="secondary">
                <Link href="/environments/create">Add</Link>
            </Button>
        </div>

        <!-- Environment Cards Grid -->
        <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <EnvironmentCard
                v-for="environment in environments"
                :key="environment.id"
                :environment="environment"
            />
        </div>
    </div>
</template>
