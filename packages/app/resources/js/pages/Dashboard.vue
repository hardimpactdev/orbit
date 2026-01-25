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
        <!-- Header -->
        aaa
        <header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">Dashboard</h1>
            </div>
            <Button v-if="$page.props.multi_environment" as-child size="sm" class="bg-lime-500 hover:bg-lime-600 text-zinc-950">
                <Link href="/environments/create">
                    <ServerIcon class="w-4 h-4 mr-1.5" />
                    Add Environment
                </Link>
            </Button>
        </header>

        <!-- Empty State -->
        <div
            v-if="environments.length === 0"
            class="rounded-lg border border-zinc-800 bg-zinc-900/50 p-8 text-center"
        >
            <ServerIcon class="w-16 h-16 mx-auto text-zinc-600 mb-4" />
            <h3 class="text-lg font-medium text-zinc-100 mb-2">No environments configured</h3>
            <p class="text-zinc-400 mb-4">Get started by adding your first environment.</p>
            <Button v-if="$page.props.multi_environment" as-child size="sm" class="bg-lime-500 hover:bg-lime-600 text-zinc-950">
                <Link href="/environments/create">
                    <ServerIcon class="w-4 h-4 mr-1.5" />
                    Add Environment
                </Link>
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
