<script setup lang="ts">
import { Head, usePage, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { LayoutDashboard, Server, Rocket, Settings } from 'lucide-vue-next';

interface FlashMessages {
    success?: string;
    error?: string;
}

interface NavigationItem {
    title: string;
    href: string;
    icon: string;
    isActive: boolean;
}

const page = usePage();

const flash = computed(() => page.props.flash as FlashMessages | undefined);
const navigation = computed(() => page.props.navigation as {
    app: {
        main: { items: NavigationItem[] };
        footer: { items: NavigationItem[] };
    };
} | undefined);

const iconMap: Record<string, any> = {
    LayoutDashboard,
    Server,
    Rocket,
    Settings,
};
</script>

<template>
    <Head>
        <title>Launchpad</title>
    </Head>

    <div class="flex min-h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col">
            <!-- Logo -->
            <div class="h-16 flex items-center px-6 border-b border-gray-200 dark:border-gray-700">
                <Link href="/" class="flex items-center">
                    <Rocket class="w-8 h-8 text-blue-600" />
                    <span class="ml-2 text-xl font-bold text-gray-900 dark:text-white">Launchpad</span>
                </Link>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 py-4 px-3">
                <ul class="space-y-1">
                    <li v-for="item in navigation?.app?.main?.items" :key="item.href">
                        <Link
                            :href="item.href"
                            class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors"
                            :class="item.isActive
                                ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                                : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
                        >
                            <component :is="iconMap[item.icon]" class="w-5 h-5 mr-3" />
                            {{ item.title }}
                        </Link>
                    </li>
                </ul>
            </nav>

            <!-- Footer Navigation -->
            <div class="border-t border-gray-200 dark:border-gray-700 py-4 px-3">
                <ul class="space-y-1">
                    <li v-for="item in navigation?.app?.footer?.items" :key="item.href">
                        <Link
                            :href="item.href"
                            class="flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors"
                            :class="item.isActive
                                ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300'
                                : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700'"
                        >
                            <component :is="iconMap[item.icon]" class="w-5 h-5 mr-3" />
                            {{ item.title }}
                        </Link>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 bg-gray-50 dark:bg-gray-900 overflow-auto">
            <!-- Flash Messages -->
            <div v-if="flash?.success" class="m-4 mb-0 rounded-lg bg-green-50 p-4 text-green-800 dark:bg-green-900/20 dark:text-green-400">
                {{ flash.success }}
            </div>
            <div v-if="flash?.error" class="m-4 mb-0 rounded-lg bg-red-50 p-4 text-red-800 dark:bg-red-900/20 dark:text-red-400">
                {{ flash.error }}
            </div>

            <!-- Page Content -->
            <slot />
        </main>
    </div>
</template>
