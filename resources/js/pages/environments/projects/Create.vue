<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    FolderGit2,
    Loader2,
    ChevronDown,
    ChevronRight,
    GitFork,
    Copy,
    AlertCircle,
    Check,
} from 'lucide-vue-next';
import { Button, Input, Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@hardimpactdev/craft-ui';
import { useGitHubStore } from '@/stores/github';

interface Environment {
    id: number;
    name: string;
    is_local: boolean;
    orchestrator_url: string | null;
}

interface TemplateFavorite {
    id: number;
    repo_url: string;
    display_name: string;
    usage_count: number;
    db_driver: string | null;
    session_driver: string | null;
    cache_driver: string | null;
    queue_driver: string | null;
}

interface TemplateDefaults {
    db_driver: string | null;
    session_driver: string | null;
    cache_driver: string | null;
    queue_driver: string | null;
}

interface RepoMetadata {
    is_template: boolean;
    framework: string;
    default_branch: string;
    repo: string;
    min_php_version: string | null;
    recommended_php_version: string;
}

const props = defineProps<{
    environment: Environment;
    recentTemplates: TemplateFavorite[];
}>();

// GitHub store for persisting selected org
const githubStore = useGitHubStore();

// Form state
const form = ref({
    name: '',
    template: '',
    is_template: false,
    fork: false,
    integrate: !!props.environment.orchestrator_url,
    linear_team_id: '',
    visibility: 'private',
    php_version: null as string | null,
    db_driver: null as string | null,
    session_driver: null as string | null,
    cache_driver: null as string | null,
    queue_driver: null as string | null,
});

const submitting = ref(false);
const submitError = ref<string | null>(null);

// Track GitHub user for import/fork detection
const githubUser = ref<string | null>(null);

// Organization selection
interface GitHubOrg {
    login: string;
    avatar_url: string;
}
const githubOrgs = ref<GitHubOrg[]>([]);
const loadingOrgs = ref(false);
const selectedOrg = ref<string | null>(null);

// Repo validation state
const repoExists = ref<boolean | null>(null);
const checkingRepo = ref(false);
const repoCheckError = ref<string | null>(null);
let repoCheckTimer: ReturnType<typeof setTimeout> | null = null;

// Slugify helper
const slugify = (str: string) =>
    str
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/(^-|-$)/g, '');

// Target repo that will be created (uses selected org or personal account)
const targetRepo = computed(() => {
    if (!selectedOrg.value || !form.value.name) return null;
    return `${selectedOrg.value}/${slugify(form.value.name)}`;
});

// Computed: Extract owner/repo from template input
const normalizedRepo = computed(() => {
    if (!form.value.template) return null;
    const match = form.value.template.match(/(?:github\.com\/)?([^/]+\/[^/\s]+?)(?:\.git)?$/i);
    return match ? match[1].toLowerCase() : null;
});

// Computed: Detect import scenario (cloning different repo)
const isImportScenario = computed(() => {
    if (!form.value.template || form.value.is_template || !selectedOrg.value || !form.value.name)
        return false;
    const slug = slugify(form.value.name);
    const targetRepoName = `${selectedOrg.value}/${slug}`.toLowerCase();
    return normalizedRepo.value !== targetRepoName;
});

// Can submit: name is set, repo check passed, not already exists
const canSubmit = computed(() => {
    if (!form.value.name.trim()) return false;
    if (checkingRepo.value) return false;
    if (repoExists.value === true) return false;
    if (submitting.value) return false;
    return true;
});

const showAdvancedOptions = ref(false);
const templateDefaults = ref<TemplateDefaults | null>(null);
const repoMetadata = ref<RepoMetadata | null>(null);
const loadingDefaults = ref(false);
const defaultsError = ref<string | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

interface OrchestratorService {
    name: string;
    enabled: boolean;
    label: string;
}

const orchestratorServices = ref<OrchestratorService[]>([]);
const loadingServices = ref(false);

const canIntegrate = computed(() => {
    return props.environment.orchestrator_url;
});

const enabledServices = computed(() => {
    return orchestratorServices.value.filter((s) => s.enabled);
});

const formatDriverName = (driver: string | null): string => {
    if (!driver) return 'Not set';
    const names: Record<string, string> = {
        sqlite: 'SQLite',
        pgsql: 'PostgreSQL',
        mysql: 'MySQL',
        file: 'File',
        database: 'Database',
        redis: 'Redis',
        sync: 'Sync',
        array: 'Array',
    };
    return names[driver] || driver;
};

// Watch for name changes and check repo availability
watch(
    () => form.value.name,
    (newName) => {
        // Clear previous timer
        if (repoCheckTimer) {
            clearTimeout(repoCheckTimer);
        }

        // Reset state
        repoExists.value = null;
        repoCheckError.value = null;

        if (!newName || !selectedOrg.value) {
            return;
        }

        // Debounce the check (wait 500ms after user stops typing)
        checkingRepo.value = true;
        const orgToCheck = selectedOrg.value;
        const nameToCheck = newName;
        repoCheckTimer = setTimeout(() => {
            checkRepoExists(orgToCheck, nameToCheck);
        }, 500);
    },
);

// Re-check when selected org changes
watch(selectedOrg, (newOrg) => {
    // Persist to store (always lowercase)
    githubStore.setSelectedOrg(newOrg?.toLowerCase() ?? null);

    if (newOrg && form.value.name) {
        // Reset and re-check
        repoExists.value = null;
        repoCheckError.value = null;
        checkingRepo.value = true;
        if (repoCheckTimer) clearTimeout(repoCheckTimer);
        const orgToCheck = newOrg;
        const nameToCheck = form.value.name;
        repoCheckTimer = setTimeout(() => {
            checkRepoExists(orgToCheck, nameToCheck);
        }, 300);
    }
});

async function checkRepoExists(org: string, name: string) {
    if (!org || !name) {
        checkingRepo.value = false;
        return;
    }

    const slug = slugify(name);
    const repo = `${org}/${slug}`;

    try {
        const response = await fetch(`/environments/${props.environment.id}/github-repo-exists`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                    '',
            },
            body: JSON.stringify({ repo }),
        });
        const result = await response.json();

        if (result.success) {
            repoExists.value = result.exists;
            repoCheckError.value = result.error || null;
        } else {
            repoCheckError.value = result.error || 'Failed to check repository';
        }
    } catch (error) {
        console.error('Failed to check repo:', error);
        repoCheckError.value = 'Failed to check repository availability';
    } finally {
        checkingRepo.value = false;
    }
}

// Watch template changes and fetch defaults
watch(
    () => form.value.template,
    (newTemplate) => {
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        if (!newTemplate) {
            templateDefaults.value = null;
            repoMetadata.value = null;
            form.value.is_template = false;
            defaultsError.value = null;
            return;
        }

        debounceTimer = setTimeout(() => {
            fetchTemplateDefaults(newTemplate);
        }, 500);
    },
);

const fetchTemplateDefaults = async (template: string) => {
    loadingDefaults.value = true;
    defaultsError.value = null;

    try {
        const response = await fetch(`/environments/${props.environment.id}/template-defaults`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                    '',
            },
            body: JSON.stringify({ template }),
        });
        const result = await response.json();

        if (result.success && result.data) {
            const drivers = result.data.drivers || result.data;
            templateDefaults.value = drivers;

            repoMetadata.value = result.data.metadata || {
                is_template: result.data.is_template || false,
                framework: result.data.type || 'unknown',
                default_branch: 'main',
                repo: template,
            };
            form.value.is_template = result.data.is_template || false;

            if (repoMetadata.value?.recommended_php_version) {
                form.value.php_version = repoMetadata.value.recommended_php_version;
            }

            if (
                drivers.db_driver ||
                drivers.session_driver ||
                drivers.cache_driver ||
                drivers.queue_driver
            ) {
                showAdvancedOptions.value = true;
            }
        } else {
            defaultsError.value = result.error || 'Could not fetch repository info';
            templateDefaults.value = null;
            repoMetadata.value = null;
            form.value.is_template = false;
        }
    } catch (error) {
        console.error('Failed to fetch repository info:', error);
        defaultsError.value = 'Failed to fetch repository info';
        templateDefaults.value = null;
        repoMetadata.value = null;
        form.value.is_template = false;
    } finally {
        loadingDefaults.value = false;
    }
};

onMounted(async () => {
    if (canIntegrate.value) {
        loadOrchestratorServices();
    }

    // Fetch GitHub user and organizations in parallel
    loadingOrgs.value = true;

    try {
        const [userResult, orgsResult] = await Promise.all([
            fetch(`/environments/${props.environment.id}/github-user`).then((r) => r.json()),
            fetch(`/environments/${props.environment.id}/github-orgs`).then((r) => r.json()),
        ]);

        if (userResult.success && userResult.user) {
            githubUser.value = userResult.user.toLowerCase();
        }

        if (orgsResult.success && orgsResult.data) {
            githubOrgs.value = orgsResult.data;
        }

        // Initialize selected org from store (sticky) or default to personal account
        if (githubStore.selectedOrg) {
            // Verify the stored org is still valid (user still has access)
            const storedOrgLower = githubStore.selectedOrg.toLowerCase();
            const validOrgs = [
                githubUser.value?.toLowerCase(),
                ...githubOrgs.value.map((o) => o.login.toLowerCase()),
            ];
            if (validOrgs.includes(storedOrgLower)) {
                selectedOrg.value = storedOrgLower;
            } else {
                // Stored org no longer valid, default to personal
                selectedOrg.value = githubUser.value;
            }
        } else {
            // No stored preference, default to personal account
            selectedOrg.value = githubUser.value;
        }
    } catch (e) {
        console.error('Failed to fetch GitHub data:', e);
        // Fallback: try to get just the user
        try {
            const userResult = await fetch(
                `/environments/${props.environment.id}/github-user`,
            ).then((r) => r.json());
            if (userResult.success && userResult.user) {
                githubUser.value = userResult.user.toLowerCase();
                selectedOrg.value = githubUser.value;
            }
        } catch {
            // Ignore
        }
    } finally {
        loadingOrgs.value = false;
    }
});

const loadOrchestratorServices = async () => {
    loadingServices.value = true;

    try {
        const response = await fetch(`/environments/${props.environment.id}/orchestrator/services`);
        const result = await response.json();

        if (result.success) {
            orchestratorServices.value = result.services || [];
        }
    } catch (error) {
        console.error('Failed to load orchestrator services:', error);
    } finally {
        loadingServices.value = false;
    }
};

const selectTemplate = (template: TemplateFavorite) => {
    form.value.template = template.repo_url;

    if (
        template.db_driver ||
        template.session_driver ||
        template.cache_driver ||
        template.queue_driver
    ) {
        form.value.db_driver = template.db_driver;
        form.value.session_driver = template.session_driver;
        form.value.cache_driver = template.cache_driver;
        form.value.queue_driver = template.queue_driver;
        showAdvancedOptions.value = true;
    }
};

const submit = () => {
    if (!canSubmit.value) return;

    submitting.value = true;
    submitError.value = null;

    // Submit to NativePHP backend using Inertia form submission
    router.post(
        `/environments/${props.environment.id}/projects`,
        {
            ...form.value,
            org: selectedOrg.value,
        },
        {
            onSuccess: () => {
                // Redirect is handled by the controller
            },
            onError: (errors) => {
                submitError.value =
                    errors.create ||
                    errors.name ||
                    Object.values(errors)[0] ||
                    'Failed to create project';
                submitting.value = false;
            },
            onFinish: () => {
                // Only reset submitting if we didn't redirect (i.e., on error)
                // onSuccess will navigate away, so we don't need to reset there
            },
        },
    );
};
</script>

<template>
    <Head title="New Project" />

    <div>
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-white">New Project</h1>
            <p class="text-zinc-400 mt-1">Create a new project in {{ environment.name }}</p>
        </div>

        <form @submit.prevent="submit">
            <!-- Project Name -->
            <div class="grid grid-cols-2 gap-8 py-6">
                <div>
                    <h3 class="text-sm font-medium text-white">Project Name</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        Display name for the app. Will be slugified for the directory and URL.
                    </p>
                </div>
                <div>
                    <Input
                        v-model="form.name"
                        type="text"
                        id="name"
                        required
                        placeholder="My Awesome Project"
                        class="w-full"
                        :class="{ 'border-red-500': repoExists === true }"
                    />
                    <!-- Slug preview -->
                    <p
                        v-if="form.name && slugify(form.name) !== form.name"
                        class="mt-1 text-xs text-zinc-500"
                    >
                        Directory:
                        <span class="font-mono text-zinc-400">{{ slugify(form.name) }}</span>
                    </p>
                </div>
            </div>

            <hr class="border-zinc-800" />

            <!-- Organization -->
            <div class="grid grid-cols-2 gap-8 py-6">
                <div>
                    <h3 class="text-sm font-medium text-white">Organization</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        GitHub account or organization where the repository will be created.
                    </p>
                </div>
                <div>
                    <div v-if="loadingOrgs" class="flex items-center text-zinc-500">
                        <Loader2 class="w-4 h-4 mr-2 animate-spin" />
                        <span class="text-sm">Loading organizations...</span>
                    </div>
                    <Select
                        v-else-if="githubUser"
                        :model-value="selectedOrg"
                        @update:model-value="selectedOrg = $event"
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select organization" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="githubUser">
                                {{ githubUser }} (Personal)
                            </SelectItem>
                            <SelectItem
                                v-for="org in githubOrgs"
                                :key="org.login"
                                :value="org.login.toLowerCase()"
                            >
                                {{ org.login }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <div v-else class="text-sm text-zinc-500">
                        Unable to load GitHub account
                    </div>
                    <!-- Repo check status -->
                    <div v-if="form.name && selectedOrg" class="mt-2 flex items-center gap-2">
                        <Loader2
                            v-if="checkingRepo"
                            class="w-3.5 h-3.5 animate-spin text-zinc-500"
                        />
                        <Check v-else-if="repoExists === false" class="w-3.5 h-3.5 text-lime-400" />
                        <AlertCircle
                            v-else-if="repoExists === true"
                            class="w-3.5 h-3.5 text-red-400"
                        />
                        <span v-if="checkingRepo" class="text-xs text-zinc-500">
                            Checking {{ targetRepo }}...
                        </span>
                        <span v-else-if="repoExists === false" class="text-xs text-lime-400">
                            {{ targetRepo }} is available
                        </span>
                        <a
                            v-else-if="repoExists === true"
                            :href="`https://github.com/${targetRepo}`"
                            target="_blank"
                            class="text-xs text-red-400 hover:underline"
                        >
                            {{ targetRepo }} already exists
                        </a>
                        <span v-else-if="repoCheckError" class="text-xs text-amber-400">
                            {{ repoCheckError }}
                        </span>
                    </div>
                </div>
            </div>

            <hr class="border-zinc-800" />

            <!-- Template / Repository -->
            <div class="grid grid-cols-2 gap-8 py-6">
                <div>
                    <h3 class="text-sm font-medium text-white">Repository</h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        GitHub repository to use as a starting point.
                    </p>
                </div>
                <div>
                    <Input
                        v-model="form.template"
                        type="text"
                        id="template"
                        placeholder="owner/repo or https://github.com/owner/repo"
                        class="w-full"
                    />
                    <!-- Loading indicator -->
                    <div v-if="loadingDefaults" class="flex items-center text-zinc-500 mt-2">
                        <Loader2 class="w-3 h-3 mr-1.5 animate-spin" />
                        <span class="text-xs">Analyzing repository...</span>
                    </div>
                    <!-- Repo type indicator -->
                    <div
                        v-else-if="repoMetadata && !defaultsError"
                        class="flex items-center gap-2 mt-2"
                    >
                        <span
                            class="text-xs px-2 py-0.5 rounded-full"
                            :class="
                                repoMetadata.is_template
                                    ? 'bg-lime-500/10 text-lime-400 border border-lime-500/20'
                                    : 'bg-blue-500/10 text-blue-400 border border-blue-500/20'
                            "
                        >
                            {{ repoMetadata.is_template ? 'Template' : 'Repository' }}
                        </span>
                        <span
                            v-if="repoMetadata.framework !== 'unknown'"
                            class="text-xs px-2 py-0.5 rounded-full bg-zinc-700 text-zinc-400"
                        >
                            {{ repoMetadata.framework }}
                        </span>
                        <span
                            v-if="repoMetadata.recommended_php_version"
                            class="text-xs px-2 py-0.5 rounded-full bg-purple-500/10 text-purple-400 border border-purple-500/20"
                        >
                            PHP {{ repoMetadata.recommended_php_version }}
                        </span>
                        <span v-if="!isImportScenario" class="text-xs text-zinc-500">
                            {{
                                repoMetadata.is_template
                                    ? 'Will create new repo from template'
                                    : 'Will clone directly'
                            }}
                        </span>
                    </div>
                    <!-- Fork/Import toggle (only for import scenario) -->
                    <div
                        v-if="isImportScenario && repoMetadata && !defaultsError"
                        class="mt-4 p-4 bg-zinc-800/50 border border-zinc-700 rounded-lg"
                    >
                        <p class="text-sm text-zinc-400 mb-3">
                            This repository belongs to someone else. How would you like to use it?
                        </p>
                        <div class="flex gap-3">
                            <button
                                type="button"
                                @click="form.fork = true"
                                class="flex-1 p-3 rounded-lg border-2 transition-colors text-left"
                                :class="
                                    form.fork
                                        ? 'border-lime-500 bg-lime-500/10'
                                        : 'border-zinc-700 hover:border-zinc-600'
                                "
                            >
                                <div class="flex items-center gap-2 mb-1">
                                    <GitFork
                                        class="w-4 h-4"
                                        :class="form.fork ? 'text-lime-400' : 'text-zinc-400'"
                                    />
                                    <span
                                        class="text-sm font-medium"
                                        :class="form.fork ? 'text-lime-400' : 'text-white'"
                                        >Fork</span
                                    >
                                </div>
                                <p class="text-xs text-zinc-500">
                                    Create a fork linked to the original repository
                                </p>
                            </button>
                            <button
                                type="button"
                                @click="form.fork = false"
                                class="flex-1 p-3 rounded-lg border-2 transition-colors text-left"
                                :class="
                                    !form.fork
                                        ? 'border-lime-500 bg-lime-500/10'
                                        : 'border-zinc-700 hover:border-zinc-600'
                                "
                            >
                                <div class="flex items-center gap-2 mb-1">
                                    <Copy
                                        class="w-4 h-4"
                                        :class="!form.fork ? 'text-lime-400' : 'text-zinc-400'"
                                    />
                                    <span
                                        class="text-sm font-medium"
                                        :class="!form.fork ? 'text-lime-400' : 'text-white'"
                                        >Import</span
                                    >
                                </div>
                                <p class="text-xs text-zinc-500">
                                    Create an independent copy with no link to original
                                </p>
                            </button>
                        </div>
                    </div>
                    <!-- Error message -->
                    <div
                        v-else-if="defaultsError && form.template"
                        class="text-xs text-amber-400 mt-2"
                    >
                        {{ defaultsError }}
                    </div>
                    <!-- Recent Templates -->
                    <div v-if="recentTemplates.length > 0" class="mt-3">
                        <p class="text-xs text-zinc-500 mb-2">Recent templates:</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="template in recentTemplates"
                                :key="template.id"
                                type="button"
                                @click="selectTemplate(template)"
                                class="px-2 py-1 text-xs bg-zinc-700 text-zinc-400 rounded hover:bg-zinc-600 hover:text-white transition-colors"
                            >
                                {{ template.display_name }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Advanced Options (only show when template is selected and defaults loaded) -->
            <template v-if="templateDefaults">
                <hr class="border-zinc-800" />

                <div class="py-6">
                    <button
                        type="button"
                        @click="showAdvancedOptions = !showAdvancedOptions"
                        class="flex items-center gap-2 text-sm font-medium text-zinc-400 hover:text-white transition-colors"
                    >
                        <component
                            :is="showAdvancedOptions ? ChevronDown : ChevronRight"
                            class="w-4 h-4"
                        />
                        Configuration Options
                        <span class="text-xs text-zinc-500">(from template)</span>
                    </button>

                    <div v-if="showAdvancedOptions" class="mt-4 space-y-6">
                        <!-- PHP Version -->
                        <div class="grid grid-cols-2 gap-8">
                            <div>
                                <h3 class="text-sm font-medium text-white">PHP Version</h3>
                                <p class="text-sm text-zinc-500 mt-1">
                                    <span v-if="repoMetadata?.min_php_version"
                                        >Minimum: PHP {{ repoMetadata.min_php_version }}+</span
                                    >
                                    <span v-else>Using latest PHP version</span>
                                </p>
                            </div>
                            <div>
                                <Select v-model="form.php_version">
                                    <SelectTrigger class="w-full">
                                        <SelectValue placeholder="Select PHP version" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="null">
                                            Auto-detect ({{ repoMetadata?.recommended_php_version || '8.5' }})
                                        </SelectItem>
                                        <SelectItem value="8.5">
                                            PHP 8.5{{ repoMetadata?.recommended_php_version === '8.5' ? ' (Recommended)' : '' }}
                                        </SelectItem>
                                        <SelectItem value="8.4">
                                            PHP 8.4{{ repoMetadata?.recommended_php_version === '8.4' ? ' (Recommended)' : '' }}
                                        </SelectItem>
                                        <SelectItem value="8.3">
                                            PHP 8.3{{ repoMetadata?.recommended_php_version === '8.3' ? ' (Recommended)' : '' }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <!-- Database Driver -->
                        <div class="grid grid-cols-2 gap-8">
                            <div>
                                <h3 class="text-sm font-medium text-white">Database</h3>
                                <p class="text-sm text-zinc-500 mt-1">
                                    Template default:
                                    <span class="text-zinc-400">{{
                                        formatDriverName(templateDefaults.db_driver)
                                    }}</span>
                                </p>
                            </div>
                            <div>
                                <Select v-model="form.db_driver">
                                    <SelectTrigger class="w-full">
                                        <SelectValue placeholder="Select database" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="null">
                                            Keep Default ({{ formatDriverName(templateDefaults.db_driver) }})
                                        </SelectItem>
                                        <SelectItem value="sqlite">SQLite</SelectItem>
                                        <SelectItem value="pgsql">PostgreSQL</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <!-- Session Driver -->
                        <div class="grid grid-cols-2 gap-8">
                            <div>
                                <h3 class="text-sm font-medium text-white">Session</h3>
                                <p class="text-sm text-zinc-500 mt-1">
                                    Template default:
                                    <span class="text-zinc-400">{{
                                        formatDriverName(templateDefaults.session_driver)
                                    }}</span>
                                </p>
                            </div>
                            <div>
                                <Select v-model="form.session_driver">
                                    <SelectTrigger class="w-full">
                                        <SelectValue placeholder="Select session driver" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="null">
                                            Keep Default ({{ formatDriverName(templateDefaults.session_driver) }})
                                        </SelectItem>
                                        <SelectItem value="file">File</SelectItem>
                                        <SelectItem value="database">Database</SelectItem>
                                        <SelectItem value="redis">Redis</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <!-- Cache Driver -->
                        <div class="grid grid-cols-2 gap-8">
                            <div>
                                <h3 class="text-sm font-medium text-white">Cache</h3>
                                <p class="text-sm text-zinc-500 mt-1">
                                    Template default:
                                    <span class="text-zinc-400">{{
                                        formatDriverName(templateDefaults.cache_driver)
                                    }}</span>
                                </p>
                            </div>
                            <div>
                                <Select v-model="form.cache_driver">
                                    <SelectTrigger class="w-full">
                                        <SelectValue placeholder="Select cache driver" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="null">
                                            Keep Default ({{ formatDriverName(templateDefaults.cache_driver) }})
                                        </SelectItem>
                                        <SelectItem value="file">File</SelectItem>
                                        <SelectItem value="database">Database</SelectItem>
                                        <SelectItem value="redis">Redis</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <!-- Queue Driver -->
                        <div class="grid grid-cols-2 gap-8">
                            <div>
                                <h3 class="text-sm font-medium text-white">Queue</h3>
                                <p class="text-sm text-zinc-500 mt-1">
                                    Template default:
                                    <span class="text-zinc-400">{{
                                        formatDriverName(templateDefaults.queue_driver)
                                    }}</span>
                                </p>
                            </div>
                            <div>
                                <Select v-model="form.queue_driver">
                                    <SelectTrigger class="w-full">
                                        <SelectValue placeholder="Select queue driver" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem :value="null">
                                            Keep Default ({{ formatDriverName(templateDefaults.queue_driver) }})
                                        </SelectItem>
                                        <SelectItem value="sync">Sync</SelectItem>
                                        <SelectItem value="database">Database</SelectItem>
                                        <SelectItem value="redis">Redis</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Orchestrator Integration -->
            <template v-if="canIntegrate">
                <hr class="border-zinc-800" />

                <div class="grid grid-cols-2 gap-8 py-6">
                    <div>
                        <h3 class="text-sm font-medium text-white">Orchestrator Integration</h3>
                        <p class="text-sm text-zinc-500 mt-1">
                            Configure project in orchestrator for full integration.
                        </p>
                        <!-- Services list -->
                        <div v-if="loadingServices" class="flex items-center text-zinc-500 mt-2">
                            <Loader2 class="w-3 h-3 mr-1.5 animate-spin" />
                            <span class="text-xs">Loading services...</span>
                        </div>
                        <div
                            v-else-if="enabledServices.length > 0"
                            class="flex items-center gap-2 mt-2"
                        >
                            <span
                                v-for="service in enabledServices"
                                :key="service.name"
                                class="text-xs px-1.5 py-0.5 rounded bg-zinc-700 text-zinc-400"
                            >
                                {{ service.label }}
                            </span>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input v-model="form.integrate" type="checkbox" class="sr-only peer" />
                            <div
                                class="w-11 h-6 bg-zinc-700 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-lime-500/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-zinc-600 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-lime-500"
                            ></div>
                        </label>
                    </div>
                </div>
            </template>

            <!-- Error Display -->
            <div v-if="submitError" class="mt-6">
                <div class="p-4 bg-red-400/10 border border-red-400/20 rounded-lg">
                    <p class="text-red-400">{{ submitError }}</p>
                </div>
            </div>

            <!-- Submit Buttons -->
            <div class="flex justify-end gap-3 pt-6 mt-6 border-t border-zinc-800">
                <Button as-child variant="ghost">
                    <Link :href="`/environments/${environment.id}/projects`">
                        Cancel
                    </Link>
                </Button>
                <Button
                    type="submit"
                    :disabled="!canSubmit"
                    variant="secondary"
                >
                    <Loader2 v-if="submitting" class="w-4 h-4 animate-spin" />
                    <FolderGit2 v-else class="w-4 h-4" />
                    Create Project
                </Button>
            </div>
        </form>
    </div>
</template>
