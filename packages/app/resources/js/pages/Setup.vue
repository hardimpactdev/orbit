<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { Loader2, Check, X, Rocket, AlertCircle, RefreshCw } from 'lucide-vue-next';

interface SetupStatus {
    needs_setup: boolean;
    cli_installed: boolean;
    cli_version: string | null;
    has_local_environment: boolean;
    has_services: boolean;
    has_tld: boolean;
    steps: Record<string, string>;
}

interface StepResult {
    step: number;
    total: number;
    message: string;
    success: boolean;
    error?: string;
}

const props = defineProps<{
    status: SetupStatus;
}>();

const isRunning = ref(false);
const isComplete = ref(false);
const hasError = ref(false);
const currentStep = ref(0);
const totalSteps = ref(6);
const currentMessage = ref('Ready to set up your local environment');
const errorMessage = ref<string | null>(null);
const completedSteps = ref<Record<string, StepResult>>({});
const tld = ref('test');

let pollInterval: ReturnType<typeof setInterval> | null = null;

const progress = computed(() => {
    return totalSteps.value > 0 ? Math.round((currentStep.value / totalSteps.value) * 100) : 0;
});

const stepList = computed(() => {
    return [
        { id: 'check_prerequisites', label: 'Checking prerequisites', order: 1 },
        { id: 'download_cli', label: 'Downloading Orbit CLI', order: 2 },
        { id: 'install_cli', label: 'Installing CLI', order: 3 },
        { id: 'init_services', label: 'Initializing Docker services', order: 4 },
        { id: 'configure_tld', label: 'Configuring TLD', order: 5 },
        { id: 'create_environment', label: 'Creating local environment', order: 6 },
    ];
});

function getStepStatus(stepId: string, order: number) {
    if (completedSteps.value[stepId]) {
        return completedSteps.value[stepId].success ? 'completed' : 'error';
    }
    if (isRunning.value && currentStep.value === order) {
        return 'in-progress';
    }
    if (currentStep.value > order) {
        return 'completed';
    }
    return 'pending';
}

async function startSetup() {
    if (isRunning.value) return;

    isRunning.value = true;
    hasError.value = false;
    errorMessage.value = null;
    currentStep.value = 0;
    completedSteps.value = {};
    currentMessage.value = 'Starting setup...';

    try {
        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';

        const response = await fetch('/setup/run', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ tld: tld.value }),
        });

        const data = await response.json();

        if (data.success) {
            // Process completed steps
            for (const [stepId, result] of Object.entries(data.steps as Record<string, StepResult>)) {
                completedSteps.value[stepId] = result;
                currentStep.value = result.step;
                currentMessage.value = result.message;

                if (!result.success) {
                    hasError.value = true;
                    errorMessage.value = result.error || 'Unknown error';
                    break;
                }
            }

            if (!hasError.value) {
                isComplete.value = true;
                currentMessage.value = 'Setup complete! Redirecting...';

                // Redirect to dashboard after short delay
                setTimeout(() => {
                    router.visit('/');
                }, 1500);
            }
        } else {
            hasError.value = true;
            errorMessage.value = data.error || 'Setup failed';
        }
    } catch (err) {
        hasError.value = true;
        errorMessage.value = err instanceof Error ? err.message : 'Network error';
    } finally {
        isRunning.value = false;
    }
}

async function retrySetup() {
    hasError.value = false;
    errorMessage.value = null;
    await startSetup();
}

onMounted(() => {
    // If already set up, redirect
    if (!props.status.needs_setup) {
        router.visit('/');
    }
});

onUnmounted(() => {
    if (pollInterval) {
        clearInterval(pollInterval);
    }
});
</script>

<template>
    <Head title="Setup - Orbit" />

    <div class="min-h-screen bg-zinc-950 flex items-center justify-center p-6">
        <div class="w-full max-w-lg">
            <!-- Logo / Header -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-lime-500/10 mb-4">
                    <Rocket class="w-8 h-8 text-lime-400" />
                </div>
                <h1 class="text-2xl font-semibold text-white mb-2">Welcome to Orbit</h1>
                <p class="text-zinc-400">
                    Let's set up your local development environment
                </p>
            </div>

            <!-- Main Card -->
            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-6">
                <!-- Status / Progress -->
                <div v-if="isRunning || isComplete || hasError" class="mb-6">
                    <div class="flex items-center mb-4">
                        <div class="mr-3">
                            <Check v-if="isComplete" class="w-6 h-6 text-lime-400" />
                            <AlertCircle v-else-if="hasError" class="w-6 h-6 text-red-400" />
                            <Loader2 v-else class="w-6 h-6 text-lime-400 animate-spin" />
                        </div>
                        <div class="flex-1">
                            <p class="text-white font-medium">
                                {{ isComplete ? 'Setup Complete!' : hasError ? 'Setup Failed' : currentMessage }}
                            </p>
                            <p v-if="errorMessage" class="text-red-400 text-sm mt-1">
                                {{ errorMessage }}
                            </p>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="mb-4">
                        <div class="flex justify-between text-sm text-zinc-500 mb-1">
                            <span>Step {{ currentStep }} of {{ totalSteps }}</span>
                            <span>{{ progress }}%</span>
                        </div>
                        <div class="w-full bg-zinc-800 rounded-full h-2">
                            <div
                                class="h-2 rounded-full transition-all duration-300"
                                :class="hasError ? 'bg-red-500' : 'bg-lime-500'"
                                :style="{ width: `${progress}%` }"
                            />
                        </div>
                    </div>

                    <!-- Step List -->
                    <ul class="space-y-2">
                        <li v-for="step in stepList" :key="step.id" class="flex items-center text-sm">
                            <span class="mr-2 flex-shrink-0">
                                <Check
                                    v-if="getStepStatus(step.id, step.order) === 'completed'"
                                    class="w-4 h-4 text-lime-400"
                                />
                                <X
                                    v-else-if="getStepStatus(step.id, step.order) === 'error'"
                                    class="w-4 h-4 text-red-400"
                                />
                                <Loader2
                                    v-else-if="getStepStatus(step.id, step.order) === 'in-progress'"
                                    class="w-4 h-4 text-lime-400 animate-spin"
                                />
                                <div v-else class="w-4 h-4 rounded-full border border-zinc-700" />
                            </span>
                            <span
                                :class="[
                                    getStepStatus(step.id, step.order) === 'completed' ? 'text-zinc-300' :
                                    getStepStatus(step.id, step.order) === 'error' ? 'text-red-400' :
                                    getStepStatus(step.id, step.order) === 'in-progress' ? 'text-white' :
                                    'text-zinc-500'
                                ]"
                            >
                                {{ step.label }}
                            </span>
                        </li>
                    </ul>

                    <!-- Retry Button -->
                    <div v-if="hasError" class="mt-4">
                        <button
                            @click="retrySetup"
                            class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-zinc-800 hover:bg-zinc-700 text-white rounded-lg transition-colors"
                        >
                            <RefreshCw class="w-4 h-4" />
                            Retry Setup
                        </button>
                    </div>
                </div>

                <!-- Initial State -->
                <div v-else>
                    <!-- TLD Configuration -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-zinc-300 mb-2">
                            Local Domain TLD
                        </label>
                        <div class="flex items-center gap-2">
                            <span class="text-zinc-500">*.project.</span>
                            <input
                                v-model="tld"
                                type="text"
                                class="flex-1 bg-zinc-800 border border-zinc-700 rounded-lg px-3 py-2 text-white placeholder-zinc-500 focus:outline-none focus:ring-2 focus:ring-lime-500 focus:border-transparent"
                                placeholder="test"
                            />
                        </div>
                        <p class="text-xs text-zinc-500 mt-2">
                            Your sites will be accessible at *.{{ tld }} (e.g., myapp.{{ tld }})
                        </p>
                    </div>

                    <!-- What will be installed -->
                    <div class="mb-6">
                        <h3 class="text-sm font-medium text-zinc-300 mb-3">What will be set up:</h3>
                        <ul class="space-y-2 text-sm text-zinc-400">
                            <li class="flex items-center gap-2">
                                <div class="w-1.5 h-1.5 rounded-full bg-lime-400" />
                                Orbit CLI (command-line tool)
                            </li>
                            <li class="flex items-center gap-2">
                                <div class="w-1.5 h-1.5 rounded-full bg-lime-400" />
                                Docker services (PostgreSQL, Redis, Mailpit)
                            </li>
                            <li class="flex items-center gap-2">
                                <div class="w-1.5 h-1.5 rounded-full bg-lime-400" />
                                DNS resolver for .{{ tld }} domains
                            </li>
                            <li class="flex items-center gap-2">
                                <div class="w-1.5 h-1.5 rounded-full bg-lime-400" />
                                Local environment configuration
                            </li>
                        </ul>
                    </div>

                    <!-- Prerequisites -->
                    <div class="mb-6 p-4 bg-zinc-800/50 border border-zinc-700/50 rounded-lg">
                        <h3 class="text-sm font-medium text-zinc-300 mb-2">Prerequisites</h3>
                        <p class="text-xs text-zinc-500">
                            Make sure you have Docker installed and running.
                            On macOS, we recommend <a href="https://orbstack.dev" target="_blank" class="text-lime-400 hover:underline">OrbStack</a> or Docker Desktop.
                        </p>
                    </div>

                    <!-- Start Button -->
                    <button
                        @click="startSetup"
                        :disabled="isRunning"
                        class="w-full flex items-center justify-center gap-2 px-4 py-3 bg-lime-500 hover:bg-lime-600 disabled:opacity-50 disabled:cursor-not-allowed text-zinc-950 font-medium rounded-lg transition-colors"
                    >
                        <Rocket class="w-5 h-5" />
                        Setup Local Environment
                    </button>
                </div>
            </div>

            <!-- Footer -->
            <div class="text-center mt-6 text-xs text-zinc-600">
                <p>Need help? Check the <a href="https://github.com/hardimpactdev/orbit-cli" target="_blank" class="text-zinc-400 hover:underline">documentation</a></p>
            </div>
        </div>
    </div>
</template>
