<script setup lang="ts">
import { Head, usePage, Link, router } from '@inertiajs/vue3';
import { computed, watch, ref, onMounted } from 'vue';
import {
    LayoutDashboard,
    Server,
    Settings,
    FolderGit2,
    HelpCircle,
    Sparkles,
    PanelLeftClose,
    PanelLeft,
    Cog,
    Workflow,
    Boxes,
} from 'lucide-vue-next';
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
const navigation = computed(
    () =>
        page.props.navigation as
            | {
                  app: {
                      main: { items: NavigationItem[] };
                      footer: { items: NavigationItem[] };
                  };
              }
            | undefined,
);
const environments = computed(() => page.props.environments as Environment[] | undefined);
const currentEnvironment = computed(
    () => page.props.currentEnvironment as Environment | null | undefined,
);

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

// Track shown flash messages to prevent duplicates
const shownFlashMessages = ref<Set<string>>(new Set());

// Show toast notifications for flash messages on navigation
router.on('finish', () => {
    const currentFlash = page.props.flash as FlashMessages | undefined;

    if (currentFlash?.success && !shownFlashMessages.value.has(currentFlash.success)) {
        shownFlashMessages.value.add(currentFlash.success);
        toast.success(currentFlash.success);
    }
    if (currentFlash?.error && !shownFlashMessages.value.has(currentFlash.error)) {
        shownFlashMessages.value.add(currentFlash.error);
        toast.error(currentFlash.error);
    }

    // Clear old messages after a short delay to allow showing the same message again later
    setTimeout(() => {
        shownFlashMessages.value.clear();
    }, 1000);
});
</script>

<template>
    <Head>
        <title>Orbit</title>
    </Head>

    <Toaster
        position="bottom-center"
        theme="dark"
        :visible-toasts="3"
        :duration="5000"
        :close-button="true"
        :toast-options="{
            unstyled: false,
            classes: {
                toast: 'bg-zinc-900 border border-zinc-700 text-white shadow-lg',
                title: 'text-white font-semibold',
                description: 'text-zinc-400',
                success: 'bg-zinc-900 border-zinc-700',
                error: 'bg-zinc-900 border-zinc-700',
                warning: 'bg-zinc-900 border-zinc-700',
            },
        }"
    />

    <!-- Fixed toggle button - never moves -->
    <button
        @click.stop="toggleSidebar"
        class="fixed top-3 left-[82px] z-[9999] p-1.5 rounded-md text-zinc-500 hover:text-white hover:bg-white/10 transition-colors pointer-events-auto"
        style="-webkit-app-region: no-drag; -webkit-user-select: none"
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
                                sidebarCollapsed ? 'justify-center' : '',
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
                                sidebarCollapsed ? 'justify-center' : '',
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

<style>
/* Sonner toast positioning - force bottom center */
section[data-sonner-toaster],
[data-sonner-toaster] {
    position: fixed !important;
    z-index: 99999 !important;
    pointer-events: none !important;
    inset: unset !important;
    top: unset !important;
    right: unset !important;
    bottom: 64px !important;
    left: 50% !important;
    transform: translateX(-50%) !important;
    width: auto !important;
}

section[data-sonner-toaster] > *,
[data-sonner-toaster] > * {
    pointer-events: auto !important;
}

/* Hide icons */
[data-sonner-toast] [data-icon] {
    display: none !important;
}

/* Toast styling */
[data-sonner-toast] {
    background: rgb(24 24 27) !important;
    border: 1px solid rgb(63 63 70) !important;
    color: white !important;
    border-radius: 8px !important;
    padding: 16px !important;
    min-width: 300px !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4) !important;
    position: relative !important;
    overflow: hidden !important;
}

[data-sonner-toast][data-type='success'],
[data-sonner-toast][data-type='error'],
[data-sonner-toast][data-type='warning'] {
    border-color: rgb(63 63 70) !important;
}

/* Progress bar / timer indicator */
[data-sonner-toast]::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    height: 3px;
    background: rgb(82 82 91);
    animation: toast-progress 5s linear forwards;
}

[data-sonner-toast][data-type='success']::after {
    background: rgb(132 204 22 / 0.6);
}

[data-sonner-toast][data-type='error']::after {
    background: rgb(239 68 68 / 0.6);
}

[data-sonner-toast][data-type='warning']::after {
    background: rgb(245 158 11 / 0.6);
}

@keyframes toast-progress {
    from {
        width: 100%;
    }
    to {
        width: 0%;
    }
}

/* Toast content styling */
[data-sonner-toast] [data-content] {
    display: flex !important;
    flex-direction: column !important;
    gap: 4px !important;
}

[data-sonner-toast] [data-title] {
    color: white !important;
    font-weight: 600 !important;
    font-size: 14px !important;
    line-height: 1.4 !important;
}

[data-sonner-toast] [data-description] {
    color: rgb(161 161 170) !important;
    font-size: 13px !important;
    line-height: 1.4 !important;
}



/* Close button */
[data-sonner-toast] [data-close-button] {
    position: absolute !important;
    top: 8px !important;
    right: 8px !important;
    background: transparent !important;
    border: none !important;
    color: rgb(113 113 122) !important;
    cursor: pointer !important;
    padding: 4px !important;
    border-radius: 4px !important;
}

[data-sonner-toast] [data-close-button]:hover {
    background: rgb(63 63 70) !important;
    color: white !important;
}
</style>
