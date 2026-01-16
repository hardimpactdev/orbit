<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, Loader2, AlertCircle, Check, X, RefreshCw, Trash2 } from 'lucide-vue-next';

interface LogEntry {
    step?: string;
    info?: string;
    error?: string;
}

interface Environment {
    id: number;
    name: string;
    host: string;
    is_local: boolean;
    status: string;
    provisioning_step: number | null;
    provisioning_total_steps: number | null;
    provisioning_log: LogEntry[] | null;
    provisioning_error: string | null;
}

const props = defineProps<{
    server: Environment;
    sshPublicKey: string;
}>();

const status = ref(props.server.status);
const currentStep = ref(props.server.provisioning_step ?? 0);
const totalSteps = ref(props.server.provisioning_total_steps ?? 17);
const log = ref<LogEntry[]>(props.server.provisioning_log ?? []);
const error = ref(props.server.provisioning_error);

let pollInterval: ReturnType<typeof setInterval> | null = null;
let isProvisioning = false;
let lastLogLength = 0;

const progress = computed(() => {
    return totalSteps.value > 0 ? Math.round((currentStep.value / totalSteps.value) * 100) : 0;
});

const isError = computed(() => status.value === 'error');

const currentStepText = computed(() => {
    const steps = log.value.filter((e) => e.step);
    return steps.length > 0 ? steps[steps.length - 1].step : 'Starting...';
});

const remoteChecklistItems = [
    { step: 3, label: 'Orbit user with sudo access' },
    { step: 6, label: 'Secure SSH configuration (key-only, no root login)' },
    { step: 8, label: 'Docker with containerized services' },
    { step: 11, label: 'PHP-FPM 8.2, 8.3 & 8.4 via Ondřej PPA' },
    { step: 13, label: 'Caddy web server' },
    { step: 17, label: 'Orbit stack (PostgreSQL, Redis, Mailpit)' },
];

const localChecklistItems = [
    { step: 2, label: 'Homebrew package manager' },
    { step: 3, label: 'OrbStack (Docker for Mac)' },
    { step: 5, label: 'PHP 8.4 & 8.5 via Homebrew' },
    { step: 6, label: 'Caddy web server' },
    { step: 9, label: 'PHP-FPM pool configuration' },
    { step: 11, label: 'DNS resolver configuration' },
    { step: 14, label: 'Docker services (PostgreSQL, Redis, Mailpit)' },
    { step: 15, label: 'Horizon queue worker (launchd)' },
];

const checklistItems = computed(() => {
    return props.server.is_local ? localChecklistItems : remoteChecklistItems;
});

const getChecklistStatus = (itemStep: number) => {
    if (currentStep.value >= itemStep) return 'completed';
    // If we're past the previous checkpoint but not at this one yet, show spinner
    const items = checklistItems.value;
    const prevItem = items.filter((i) => i.step < itemStep).pop();
    if (prevItem && currentStep.value >= prevItem.step) return 'in-progress';
    if (currentStep.value > 0) return 'pending';
    return 'pending';
};

async function startProvisioning() {
    if (isProvisioning) return;
    isProvisioning = true;

    try {
        await fetch(`/provision/${props.server.id}/run`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ||
                    '',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ ssh_public_key: props.sshPublicKey }),
        });
    } catch (err) {
        console.error('Provisioning start error:', err);
    }

    isProvisioning = false;
}

async function pollStatus() {
    try {
        const response = await fetch(`/provision/${props.server.id}/status`);
        const data = await response.json();

        status.value = data.status;
        currentStep.value = data.provisioning_step ?? 0;
        totalSteps.value = data.provisioning_total_steps ?? 17;
        error.value = data.provisioning_error;

        if (data.provisioning_log && data.provisioning_log.length !== lastLogLength) {
            log.value = data.provisioning_log;
            lastLogLength = data.provisioning_log.length;

            // Scroll log to bottom
            await nextTick();
            const logEl = document.getElementById('provisioning-log');
            if (logEl) logEl.scrollTop = logEl.scrollHeight;
        }

        if (data.status === 'active') {
            stopPolling();
            window.location.reload();
        } else if (data.status === 'error') {
            stopPolling();
        }
    } catch (err) {
        console.error('Status poll error:', err);
    }
}

function startPolling() {
    pollInterval = setInterval(pollStatus, 500);
}

function stopPolling() {
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

async function retryProvisioning() {
    // Reset state
    status.value = 'provisioning';
    currentStep.value = 0;
    log.value = [];
    error.value = null;
    lastLogLength = 0;

    startPolling();
    await startProvisioning();
}

function deleteServer() {
    if (confirm('Are you sure you want to delete this environment?')) {
        router.delete(`/environments/${props.server.id}`);
    }
}

onMounted(() => {
    if (props.server.status === 'provisioning') {
        startPolling();
        setTimeout(startProvisioning, 100);
    }
});

onUnmounted(() => {
    stopPolling();
});
</script>

<template>
    <Head :title="`${server.name} - Provisioning`" />

    <div class="p-6 max-w-2xl">
        <div class="mb-6">
            <Link href="/servers" class="text-blue-600 hover:text-blue-800 flex items-center">
                <ChevronLeft class="w-4 h-4 mr-1" />
                Back to Environments
            </Link>
        </div>

        <div class="flex justify-between items-start mb-6">
            <div>
                <h2 class="text-2xl font-bold text-gray-800 dark:text-white">{{ server.name }}</h2>
                <p class="text-gray-500 dark:text-gray-400">{{ server.host }}</p>
            </div>
        </div>

        <!-- Provisioning Status Card -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
            <div class="flex items-center mb-4">
                <div class="mr-3">
                    <AlertCircle v-if="isError" class="w-8 h-8 text-red-500" />
                    <Loader2 v-else class="w-8 h-8 text-blue-500 animate-spin" />
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white">
                        {{ isError ? 'Provisioning Failed' : 'Setting up Environment' }}
                    </h3>
                    <p class="text-gray-500 dark:text-gray-400">
                        {{
                            isError ? error : 'Installing the Orbit stack on the remote machine...'
                        }}
                    </p>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="mb-4">
                <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 mb-1">
                    <span>Step {{ currentStep }} of {{ totalSteps }}</span>
                    <span>{{ progress }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                    <div
                        class="h-2.5 rounded-full transition-all duration-300"
                        :class="isError ? 'bg-red-500' : 'bg-blue-600'"
                        :style="{ width: `${progress}%` }"
                    />
                </div>
            </div>

            <!-- Current Step -->
            <div
                v-if="!isError"
                class="flex items-center text-sm font-medium text-blue-600 dark:text-blue-400 mb-4"
            >
                <Loader2 class="w-4 h-4 mr-2 animate-spin" />
                <span>{{ currentStepText }}</span>
            </div>

            <!-- Log Output -->
            <div
                id="provisioning-log"
                class="bg-gray-100 dark:bg-gray-900 rounded-lg p-4 max-h-64 overflow-y-auto font-mono text-sm"
            >
                <template v-for="(entry, index) in log" :key="index">
                    <div
                        v-if="entry.step"
                        class="flex items-center"
                        :class="
                            index === log.filter((e) => e.step).length - 1 && !isError
                                ? 'text-blue-600 dark:text-blue-400'
                                : 'text-green-600 dark:text-green-400'
                        "
                    >
                        <Loader2
                            v-if="
                                index === log.filter((e) => e.step).length - 1 &&
                                !isError &&
                                status === 'provisioning'
                            "
                            class="w-4 h-4 mr-2 flex-shrink-0 animate-spin"
                        />
                        <Check v-else class="w-4 h-4 mr-2 flex-shrink-0" />
                        {{ entry.step }}
                    </div>
                    <div v-else-if="entry.info" class="text-gray-500 dark:text-gray-400 pl-6">
                        {{ entry.info }}
                    </div>
                    <div
                        v-else-if="entry.error"
                        class="flex items-center text-red-600 dark:text-red-400"
                    >
                        <X class="w-4 h-4 mr-2 flex-shrink-0" />
                        {{ entry.error }}
                    </div>
                </template>
            </div>

            <!-- Error Actions -->
            <div v-if="isError" class="mt-4 flex space-x-3">
                <button
                    @click="retryProvisioning"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center"
                >
                    <RefreshCw class="w-4 h-4 mr-2" />
                    Retry Provisioning
                </button>
                <button
                    @click="deleteServer"
                    class="px-4 py-2 border border-red-300 dark:border-red-600 text-red-600 dark:text-red-400 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/30"
                >
                    <Trash2 class="w-4 h-4 inline mr-2" />
                    Delete Environment
                </button>
            </div>
        </div>

        <!-- What's Being Installed -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
                {{ server.is_local ? "What's being installed (Mac)" : "What's being installed" }}
            </h3>
            <ul class="space-y-2 text-gray-600 dark:text-gray-400">
                <li v-for="item in checklistItems" :key="item.step" class="flex items-center">
                    <span class="mr-2">
                        <Check
                            v-if="getChecklistStatus(item.step) === 'completed'"
                            class="w-5 h-5 text-green-500"
                        />
                        <Loader2
                            v-else-if="getChecklistStatus(item.step) === 'in-progress'"
                            class="w-5 h-5 text-blue-500 animate-spin"
                        />
                        <div v-else class="w-5 h-5 rounded-full border-2 border-gray-300" />
                    </span>
                    {{ item.label }}
                </li>
            </ul>
        </div>
    </div>
</template>
