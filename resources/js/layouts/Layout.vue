<script setup lang="ts">
import { Head, usePage, Link, router } from '@inertiajs/vue3';
import { computed, watch, ref, onMounted } from 'vue';
import { LayoutDashboard, Server, Settings, FolderGit2, HelpCircle, Sparkles, PanelLeftClose, PanelLeft, Cog, Workflow, Boxes } from 'lucide-vue-next';
import { Toaster, toast } from 'vue-sonner';
import EnvironmentSwitcher from '@/components/EnvironmentSwitcher.vue';

interface FlashMessages {
    success?: string;
    error?: string;
}

interface NavigationItem {
    title: string;
    href: string;
    icon: string;
    isActive: boolean;
    enabled?: boolean;
}

interface Environment {
    id: number;
    name: string;
    host: string;
    is_local: boolean;
    is_default: boolean;
}

const page = usePage();

const flash = computed(() => page.props.flash as FlashMessages | undefined);
const navigation = computed(() => page.props.navigation as {
    app: {
        main: { items: NavigationItem[] };
        footer: { items: NavigationItem[] };
    };
} | undefined);
const environments = computed(() => page.props.environments as Environment[] | undefined);
const currentEnvironment = computed(() => page.props.currentEnvironment as Environment | null | undefined);

const iconMap: Record<string, any> = {
    LayoutDashboard,
    Server,
    Settings,
    FolderGit2,
    HelpCircle,
    Sparkles,
    Cog,
    Workflow,
    Boxes,
};

// Sidebar collapsed state
const sidebarCollapsed = ref(false);

onMounted(() => {
    const saved = localStorage.getItem('sidebar-collapsed');
    if (saved !== null) {
        sidebarCollapsed.value = saved === 'true';
    }
});

const toggleSidebar = () => {
    sidebarCollapsed.value = !sidebarCollapsed.value;
    localStorage.setItem('sidebar-collapsed', String(sidebarCollapsed.value));
};

// Show toast notifications for flash messages
watch(flash, (newFlash) => {
    if (newFlash?.success) {
        toast.success(newFlash.success);
    }
    if (newFlash?.error) {
        toast.error(newFlash.error);
    }
}, { immediate: true });

// Also listen for Inertia navigate events to catch flash messages
router.on('finish', () => {
    const currentFlash = page.props.flash as FlashMessages | undefined;
    if (currentFlash?.success) {
        toast.success(currentFlash.success);
    }
    if (currentFlash?.error) {
        toast.error(currentFlash.error);
    }
});
</script>

<template>
    <Head>
        <title>Launchpad</title>
    </Head>

    <Toaster position="bottom-center" theme="dark" :toast-options="{ class: 'bg-zinc-900 border-zinc-800 text-white' }" />

    <!-- Fixed toggle button - never moves -->
    <button
        @click.stop="toggleSidebar"
        class="fixed top-3 left-[82px] z-[9999] p-1.5 rounded-md text-zinc-500 hover:text-white hover:bg-white/10 transition-colors pointer-events-auto"
        style="-webkit-app-region: no-drag; -webkit-user-select: none;"
        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
    >
        <PanelLeft v-if="sidebarCollapsed" class="w-4 h-4" />
        <PanelLeftClose v-else class="w-4 h-4" />
    </button>

    <div class="flex h-screen overflow-hidden bg-[#09090b]">
        <!-- Sidebar - Catalyst style -->
        <aside
            class="bg-[#09090b] flex flex-col border-r border-zinc-800 transition-all duration-200 h-full overflow-hidden"
            :class="sidebarCollapsed ? 'w-16' : 'w-56'"
        >
            <!-- Draggable header area for window movement -->
            <div class="h-11 flex">
                <!-- Left drag region (for traffic lights) -->
                <div class="w-[78px] drag-region"></div>
                <!-- Gap for toggle button - not draggable -->
                <div class="w-10"></div>
                <!-- Right drag region (rest of header) -->
                <div class="flex-1 drag-region"></div>
            </div>

            <!-- Environment Switcher -->
            <div class="px-3 pb-3 border-b border-zinc-800">
                <EnvironmentSwitcher
                    :environments="environments || []"
                    :current-environment="currentEnvironment || null"
                    :collapsed="sidebarCollapsed"
                />
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-3 py-4">
                <ul class="space-y-1">
                    <li v-for="item in navigation?.app?.main?.items" :key="item.href">
                        <Link
                            :href="item.href"
                            class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors"
                            :class="[
                                item.isActive
                                    ? 'bg-white/10 text-white font-medium'
                                    : item.enabled === false
                                        ? 'text-zinc-600 hover:text-zinc-400 hover:bg-white/5'
                                        : 'text-zinc-400 hover:text-white hover:bg-white/5',
                                sidebarCollapsed ? 'justify-center' : ''
                            ]"
                            :title="sidebarCollapsed ? item.title : undefined"
                        >
                            <component
                                :is="iconMap[item.icon]"
                                class="w-5 h-5 flex-shrink-0"
                                :class="{ 'opacity-50': item.enabled === false }"
                            />
                            <span v-if="!sidebarCollapsed" class="flex items-center gap-2">
                                {{ item.title }}
                                <span
                                    v-if="item.enabled === false"
                                    class="text-[10px] px-1.5 py-0.5 rounded bg-zinc-800 text-zinc-500"
                                >
                                    Setup
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </nav>

            <!-- Footer Navigation -->
            <div class="px-3 py-4 border-t border-zinc-800">
                <ul class="space-y-1">
                    <li v-for="item in navigation?.app?.footer?.items" :key="item.href">
                        <Link
                            :href="item.href"
                            class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors"
                            :class="[
                                item.isActive
                                    ? 'bg-white/10 text-white font-medium'
                                    : 'text-zinc-400 hover:text-white hover:bg-white/5',
                                sidebarCollapsed ? 'justify-center' : ''
                            ]"
                            :title="sidebarCollapsed ? item.title : undefined"
                        >
                            <component :is="iconMap[item.icon]" class="w-5 h-5 flex-shrink-0" />
                            <span v-if="!sidebarCollapsed">{{ item.title }}</span>
                        </Link>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content - slightly lighter than sidebar -->
        <main class="flex-1 bg-[#18181b] overflow-auto">
            <div class="max-w-5xl mx-auto px-8 py-8">
                <slot />
            </div>
        </main>
    </div>
</template>

<style scoped>
/* macOS window dragging support */
.drag-region {
    -webkit-app-region: drag;
}
.no-drag {
    -webkit-app-region: no-drag;
}
</style>
