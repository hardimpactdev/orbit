<script setup lang="ts">
import { ref, onMounted, computed, watch } from "vue";
import { Head, useForm, router, usePage } from "@inertiajs/vue3";
import { toast } from "vue-sonner";
import DnsSettings from "@/components/DnsSettings.vue";
import Modal from "@/components/Modal.vue";
import {
    Loader2,
    Trash2,
    Plus,
    AlertTriangle,
    Stethoscope,
    RefreshCw,
    CheckCircle2,
    XCircle,
    AlertTriangleIcon,
    ChevronDown,
    Globe,
    Key,
    Copy,
    Star,
    Pencil,
    FileCode2,
    ExternalLink,
    FolderOpen,
    Settings2,
    Wrench,
} from "lucide-vue-next";
import {
    Button,
    Badge,
    Input,
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
    Switch,
    Textarea,
    Label,
    Separator,
} from "@hardimpactdev/craft-ui";

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    port: number;
    is_local: boolean;
    editor_scheme: string | null;
    external_access: boolean;
    external_host: string | null;
}

interface SshKey {
    id: number;
    name: string;
    public_key: string;
    key_type: string;
    is_default: boolean;
}

interface AvailableKey {
    content: string;
    type: string;
}

interface TemplateFavorite {
    id: number;
    repo_url: string;
    display_name: string;
    usage_count: number;
    last_used_at: string | null;
}

interface Editor {
    scheme: string;
    name: string;
}

interface Config {
    paths: string[];
    tld: string;
    default_php_version: string;
    available_php_versions?: string[];
}

const props = defineProps<{
    environment: Environment;
    remoteApiUrl: string | null;
    editor: Editor;
    editorOptions: Record<string, string>;
    sshKeys: SshKey[];
    availableSshKeys: Record<string, AvailableKey>;
    templateFavorites: TemplateFavorite[];
    notificationsEnabled: boolean;
    menuBarEnabled: boolean;
}>();

const page = usePage();
const multiEnvironment = computed(() => page.props.multi_environment);
const orbitVersion = computed(() => (page.props.orbit_version as string) || "unknown");

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || "";

// Tab navigation - 4 main tabs
type TabId = "configuration" | "dns" | "templates" | "advanced";

const tabs = computed(() => {
    const allTabs = [
        { id: "configuration" as TabId, label: "Configuration", icon: Settings2 },
        { id: "dns" as TabId, label: "DNS", icon: Globe },
        { id: "templates" as TabId, label: "Templates", icon: FileCode2 },
        { id: "advanced" as TabId, label: "Advanced", icon: Wrench },
    ];

    return allTabs;
});

const activeTab = ref<TabId>("configuration");

// Whether this is an external environment viewed from desktop
const isExternalEnvironment = computed(() => !props.environment.is_local && props.remoteApiUrl);

// Environment form
const envForm = useForm({
    name: props.environment.name,
    host: props.environment.host,
    user: props.environment.user,
    port: props.environment.port,
    editor_scheme: props.editor.scheme,
});

// Instance info sync (for external environments)
const instanceInfoLoading = ref(false);
const instanceInfoError = ref<string | null>(null);
const nameSaving = ref(false);

// CLI config
const config = ref<Config | null>(null);
const configLoading = ref(true);
const configSaving = ref(false);
const editPaths = ref<string[]>([]);
const editTld = ref("");
const editPhpVersion = ref("8.4");
const availablePhpVersions = ref<string[]>(["8.3", "8.4", "8.5"]);

// Delete confirmation
const showDeleteConfirm = ref(false);
const deleteConfirmName = ref("");

const tld = ref("test");

// Doctor/Health Check state
interface HealthCheck {
    status: "ok" | "warning" | "error";
    message: string;
    details?: Record<string, unknown>;
}

interface DoctorResult {
    success: boolean;
    status: "healthy" | "degraded" | "unhealthy";
    checks: Record<string, HealthCheck>;
    summary: {
        passed: number;
        warnings: number;
        errors: number;
        total: number;
        messages: string[];
    };
}

const doctorRunning = ref(false);
const doctorResult = ref<DoctorResult | null>(null);
const doctorError = ref<string | null>(null);
const expandedChecks = ref<Set<string>>(new Set());

const checkLabels: Record<string, string> = {
    ssh: "SSH Connection",
    cli: "CLI Installation",
    docker: "Docker Services",
    api: "API Connectivity",
    environment_dns: "Environment DNS",
    local_dns: "Local DNS",
    config: "Configuration",
};

function toggleCheckExpanded(key: string) {
    if (expandedChecks.value.has(key)) {
        expandedChecks.value.delete(key);
    } else {
        expandedChecks.value.add(key);
    }
}

const fixingChecks = ref<Set<string>>(new Set());

async function runDoctor() {
    doctorRunning.value = true;
    doctorError.value = null;
    doctorResult.value = null;

    try {
        const response = await fetch(`/environments/${props.environment.id}/doctor`);
        const result = await response.json();

        if (result.success) {
            doctorResult.value = result;
        } else {
            doctorError.value = result.error || "Health check failed";
        }
    } catch (error) {
        doctorError.value = "Failed to run health check";
    } finally {
        doctorRunning.value = false;
    }
}

async function fixIssue(checkKey: string) {
    fixingChecks.value.add(checkKey);

    try {
        const response = await fetch(
            `/environments/${props.environment.id}/doctor/fix/${checkKey}`,
            {
                method: "POST",
                headers: {
                    "X-CSRF-TOKEN": csrfToken,
                    "Content-Type": "application/json",
                },
            },
        );
        const result = await response.json();

        if (result.success) {
            await runDoctor();
        } else {
            doctorError.value = result.message || "Failed to fix issue";
        }
    } catch (error) {
        doctorError.value = "Failed to fix issue";
    } finally {
        fixingChecks.value.delete(checkKey);
    }
}

// Instance info sync (for external environments)
async function loadInstanceInfo() {
    if (!isExternalEnvironment.value || !props.remoteApiUrl) return;

    instanceInfoLoading.value = true;
    instanceInfoError.value = null;

    try {
        const response = await fetch(`${props.remoteApiUrl}/instance-info`);
        const result = await response.json();

        if (result.success && result.data) {
            envForm.name = result.data.name;
        } else {
            instanceInfoError.value = result.error || "Failed to load instance info";
        }
    } catch (error) {
        instanceInfoError.value = "Failed to connect to remote environment";
    } finally {
        instanceInfoLoading.value = false;
    }
}

async function saveRemoteName() {
    if (!props.remoteApiUrl) return;

    nameSaving.value = true;
    instanceInfoError.value = null;

    try {
        const response = await fetch(`${props.remoteApiUrl}/instance-info`, {
            method: "PUT",
            headers: {
                "Content-Type": "application/json",
            },
            body: JSON.stringify({ name: envForm.name }),
        });

        const result = await response.json();

        if (!result.success) {
            instanceInfoError.value = result.error || "Failed to update name";
        }
    } catch (error) {
        instanceInfoError.value = "Failed to connect to remote environment";
    } finally {
        nameSaving.value = false;
    }
}

async function loadConfig() {
    configLoading.value = true;
    try {
        const response = await fetch(`/api/environments/${props.environment.id}/config`);
        const result = await response.json();

        if (result.success) {
            config.value = result.data;
            editPaths.value = [...(result.data.paths || [])];
            if (editPaths.value.length === 0) editPaths.value.push("");
            editTld.value = result.data.tld || "test";
            editPhpVersion.value = result.data.default_php_version || "8.4";
            tld.value = result.data.tld || "test";
            if (result.data.available_php_versions?.length) {
                availablePhpVersions.value = result.data.available_php_versions;
            }
        }
    } catch (error) {
        console.error("Failed to load config:", error);
    } finally {
        configLoading.value = false;
    }
}

async function saveEnvSettings() {
    if (isExternalEnvironment.value) {
        await saveRemoteName();
    }
    envForm.post(`/environments/${props.environment.id}/settings`);
}

function addPath() {
    editPaths.value.push("");
}

function removePath(index: number) {
    editPaths.value.splice(index, 1);
}

// Directory picker state
const showDirectoryPicker = ref(false);
const directoryPickerIndex = ref<number | null>(null);
const browsingPath = ref("~");
const browsingDirectories = ref<{ name: string; path: string }[]>([]);
const browsingParent = ref<string | null>(null);
const browsingLoading = ref(false);
const browsingError = ref<string | null>(null);

async function openDirectoryPicker(index: number) {
    directoryPickerIndex.value = index;
    browsingPath.value = editPaths.value[index] || "~";
    showDirectoryPicker.value = true;
    await loadDirectories(browsingPath.value);
}

async function loadDirectories(path: string) {
    browsingLoading.value = true;
    browsingError.value = null;

    try {
        const response = await fetch(
            `/environments/${props.environment.id}/browse-directories?path=${encodeURIComponent(path)}`,
        );
        const result = await response.json();

        if (result.success) {
            browsingPath.value = result.data.current;
            browsingDirectories.value = result.data.directories;
            browsingParent.value = result.data.parent;
        } else {
            browsingError.value = result.error || "Failed to load directories";
        }
    } catch (error) {
        browsingError.value = "Failed to load directories";
    } finally {
        browsingLoading.value = false;
    }
}

function navigateToDirectory(path: string) {
    loadDirectories(path);
}

function selectCurrentDirectory() {
    if (directoryPickerIndex.value !== null) {
        editPaths.value[directoryPickerIndex.value] = browsingPath.value;
    }
    closeDirectoryPicker();
}

function closeDirectoryPicker() {
    showDirectoryPicker.value = false;
    directoryPickerIndex.value = null;
}

async function saveConfig() {
    const paths = editPaths.value.filter((p) => p.trim() !== "");
    if (paths.length === 0) {
        toast.error("Please add at least one site path");
        return;
    }

    configSaving.value = true;
    try {
        const response = await fetch(`/environments/${props.environment.id}/config`, {
            method: "POST",
            headers: { "X-CSRF-TOKEN": csrfToken, "Content-Type": "application/json" },
            body: JSON.stringify({
                paths,
                tld: editTld.value.trim() || "test",
                default_php_version: editPhpVersion.value,
            }),
        });
        const result = await response.json();

        if (result.success) {
            config.value = result.data;
            tld.value = editTld.value.trim() || "test";
            toast.success("Configuration saved");
        } else {
            toast.error("Failed to save config", {
                description: result.error || "Unknown error",
            });
        }
    } catch {
        toast.error("Failed to save config");
    } finally {
        configSaving.value = false;
    }
}

function confirmDelete() {
    showDeleteConfirm.value = true;
    deleteConfirmName.value = "";
}

function cancelDelete() {
    showDeleteConfirm.value = false;
    deleteConfirmName.value = "";
}

function deleteEnvironment() {
    if (deleteConfirmName.value !== props.environment.name) {
        return;
    }

    router.delete(`/environments/${props.environment.id}`, {
        onSuccess: () => {
            // Will redirect to dashboard
        },
    });
}

onMounted(() => {
    loadConfig();
    if (isExternalEnvironment.value) {
        loadInstanceInfo();
    }
});

// === External Access ===
const externalAccessForm = useForm({
    external_access: props.environment.external_access ?? false,
    external_host: props.environment.external_host ?? "",
});

const saveExternalAccess = () => {
    externalAccessForm.post(`/environments/${props.environment.id}/configuration/external-access`);
};

// Removed automatic save on toggle off - now handled by explicit save button

// === SSH Keys ===
const showKeyModal = ref(false);
const editingKey = ref<SshKey | null>(null);

const keyForm = useForm({
    name: "",
    public_key: "",
});

const openAddKeyModal = () => {
    editingKey.value = null;
    keyForm.reset();
    showKeyModal.value = true;
};

const openEditKeyModal = (key: SshKey) => {
    editingKey.value = key;
    keyForm.name = key.name;
    keyForm.public_key = key.public_key;
    showKeyModal.value = true;
};

const closeKeyModal = () => {
    showKeyModal.value = false;
    editingKey.value = null;
    keyForm.reset();
};

const saveKey = () => {
    if (editingKey.value) {
        keyForm.put(`/ssh-keys/${editingKey.value.id}`, {
            onSuccess: closeKeyModal,
        });
    } else {
        keyForm.post("/ssh-keys", {
            onSuccess: closeKeyModal,
        });
    }
};

const deleteKey = (key: SshKey) => {
    if (confirm("Delete this SSH key?")) {
        router.delete(`/ssh-keys/${key.id}`);
    }
};

const setDefaultKey = (key: SshKey) => {
    router.post(`/ssh-keys/${key.id}/default`);
};

const copyKey = async (key: SshKey) => {
    await navigator.clipboard.writeText(key.public_key);
    toast.success("Copied to clipboard");
};

const importKey = (event: Event) => {
    const select = event.target as HTMLSelectElement;
    const option = select.selectedOptions[0];
    if (option.value) {
        keyForm.public_key = option.value;
        const keyName = option.dataset.name;
        if (keyName && !keyForm.name) {
            keyForm.name = keyName;
        }
    }
};

const truncateKey = (key: string, length = 80) => {
    return key.length > length ? key.substring(0, length) + "..." : key;
};

// === Template Favorites ===
const showTemplateModal = ref(false);
const editingTemplate = ref<TemplateFavorite | null>(null);

const templateForm = useForm({
    repo_url: "",
    display_name: "",
});

const openAddTemplateModal = () => {
    editingTemplate.value = null;
    templateForm.reset();
    showTemplateModal.value = true;
};

const openEditTemplateModal = (template: TemplateFavorite) => {
    editingTemplate.value = template;
    templateForm.repo_url = template.repo_url;
    templateForm.display_name = template.display_name;
    showTemplateModal.value = true;
};

const closeTemplateModal = () => {
    showTemplateModal.value = false;
    editingTemplate.value = null;
    templateForm.reset();
};

const saveTemplate = () => {
    if (editingTemplate.value) {
        templateForm.put(`/template-favorites/${editingTemplate.value.id}`, {
            onSuccess: closeTemplateModal,
        });
    } else {
        templateForm.post("/template-favorites", {
            onSuccess: closeTemplateModal,
        });
    }
};

const deleteTemplate = (template: TemplateFavorite) => {
    if (confirm("Delete this template?")) {
        router.delete(`/template-favorites/${template.id}`);
    }
};

const extractRepoName = (url: string): string => {
    const match = url.match(/(?:github\.com\/)?([^/]+)\/([^/]+)/);
    return match ? match[2] : url;
};

const onRepoUrlChange = () => {
    if (!editingTemplate.value && templateForm.repo_url && !templateForm.display_name) {
        templateForm.display_name = extractRepoName(templateForm.repo_url);
    }
};

const openGitHub = (url: string) => {
    const fullUrl = url.startsWith("http") ? url : `https://github.com/${url}`;
    window.open(fullUrl, "_blank");
};

// === Notifications & Menu Bar (Desktop only) ===
const notificationForm = useForm({
    enabled: props.notificationsEnabled,
});

const toggleNotifications = () => {
    notificationForm.enabled = !notificationForm.enabled;
    notificationForm.post("/settings/notifications");
};

const menuBarForm = useForm({
    enabled: props.menuBarEnabled,
});

const toggleMenuBar = () => {
    menuBarForm.enabled = !menuBarForm.enabled;
    menuBarForm.post("/settings/menu-bar");
};
</script>

<template>
    <Head :title="`Configuration - ${environment.name}`" />

    <div class="flex h-full">
        <!-- Configuration Vertical Tabs Navigation -->
        <aside class="sticky top-0 h-screen w-52 shrink-0 bg-zinc-950">
            <div class="flex h-full flex-col">
                <!-- Header -->
                <div class="p-6">
                    <h1 class="text-lg font-semibold text-white">Configuration</h1>
                    <p class="text-sm text-zinc-400">{{ environment.name }}</p>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 space-y-1 px-3">
                    <button
                        v-for="tab in tabs"
                        :key="tab.id"
                        type="button"
                        @click="activeTab = tab.id"
                        :class="[
                            'flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                            activeTab === tab.id
                                ? 'bg-zinc-800 text-white'
                                : 'text-zinc-400 hover:bg-zinc-800/50 hover:text-white',
                        ]"
                    >
                        <component :is="tab.icon" class="h-4 w-4" />
                        {{ tab.label }}
                    </button>
                </nav>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6 lg:p-8 overflow-auto">
            <div class="max-w-2xl mx-auto">
                <!-- Configuration Tab -->
                <div v-if="activeTab === 'configuration'">
                    <!-- Environment Settings Section -->
                    <form @submit.prevent="saveEnvSettings">
                        <!-- Environment Name -->
                        <div class="flex flex-col sm:flex-row sm:items-start gap-4 py-6">
                            <div class="sm:w-1/2 space-y-1">
                                <Label class="text-sm font-medium text-white">Environment Name</Label>
                                <p class="text-xs text-zinc-500">
                                    Display name for this environment.
                                    <template v-if="isExternalEnvironment">
                                        <br /><span class="text-zinc-600">Changes will be synced to the remote environment.</span>
                                    </template>
                                </p>
                            </div>
                            <div class="sm:w-1/2">
                                <div class="relative">
                                    <Input
                                        v-model="envForm.name"
                                        type="text"
                                        class="w-full"
                                        :disabled="instanceInfoLoading"
                                    />
                                    <Loader2
                                        v-if="instanceInfoLoading"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 animate-spin text-zinc-500"
                                    />
                                </div>
                                <p v-if="envForm.errors.name" class="mt-2 text-sm text-red-400">
                                    {{ envForm.errors.name }}
                                </p>
                                <p v-if="instanceInfoError" class="mt-2 text-sm text-amber-400">
                                    {{ instanceInfoError }}
                                </p>
                            </div>
                        </div>

                        <Separator class="bg-zinc-800" />

                        <!-- Code Editor -->
                        <div class="flex flex-col sm:flex-row sm:items-start gap-4 py-6">
                            <div class="sm:w-1/2 space-y-1">
                                <Label class="text-sm font-medium text-white">Code Editor</Label>
                                <p class="text-xs text-zinc-500">Select your preferred editor for opening files.</p>
                            </div>
                            <div class="sm:w-1/2">
                                <Select v-model="envForm.editor_scheme">
                                    <SelectTrigger class="w-full">
                                        <SelectValue placeholder="Select editor" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="(name, scheme) in editorOptions"
                                            :key="scheme"
                                            :value="scheme"
                                        >
                                            {{ name }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <!-- SSH Connection (remote only) -->
                        <template v-if="!environment.is_local">
                            <Separator class="bg-zinc-800" />

                            <div class="flex flex-col sm:flex-row sm:items-start gap-4 py-6">
                                <div class="sm:w-1/2 space-y-1">
                                    <Label class="text-sm font-medium text-white">SSH Connection</Label>
                                    <p class="text-xs text-zinc-500">Host, user, and port for SSH access.</p>
                                </div>
                                <div class="sm:w-1/2 space-y-3">
                                    <div class="space-y-1.5">
                                        <Label class="text-xs text-zinc-500">Host</Label>
                                        <Input v-model="envForm.host" type="text" class="font-mono" />
                                        <p v-if="envForm.errors.host" class="text-sm text-red-400">
                                            {{ envForm.errors.host }}
                                        </p>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div class="space-y-1.5">
                                            <Label class="text-xs text-zinc-500">User</Label>
                                            <Input v-model="envForm.user" type="text" class="font-mono" />
                                            <p v-if="envForm.errors.user" class="text-sm text-red-400">
                                                {{ envForm.errors.user }}
                                            </p>
                                        </div>
                                        <div class="space-y-1.5">
                                            <Label class="text-xs text-zinc-500">Port</Label>
                                            <Input v-model="envForm.port" type="number" class="font-mono" />
                                            <p v-if="envForm.errors.port" class="text-sm text-red-400">
                                                {{ envForm.errors.port }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <Separator class="bg-zinc-800" />

                        <div class="flex justify-end py-6">
                            <Button
                                type="submit"
                                :disabled="envForm.processing || nameSaving"
                                class="bg-lime-500 hover:bg-lime-600 text-zinc-950"
                            >
                                {{ envForm.processing || nameSaving ? "Saving..." : "Save Environment" }}
                            </Button>
                        </div>
                    </form>

                    <Separator class="bg-zinc-800 my-4" />

                    <!-- CLI Configuration Section -->
                    <div v-if="configLoading" class="text-zinc-500 text-sm py-6">
                        <Loader2 class="w-4 h-4 inline animate-spin mr-2" />
                        Loading configuration...
                    </div>

                    <template v-else>
                        <!-- Site Paths -->
                        <div class="flex flex-col sm:flex-row sm:items-start gap-4 py-6">
                            <div class="sm:w-1/2 space-y-1">
                                <Label class="text-sm font-medium text-white">Project Paths</Label>
                                <p class="text-xs text-zinc-500">Directories where your projects are located.</p>
                            </div>
                            <div class="sm:w-1/2 space-y-2">
                                <div
                                    v-for="(path, index) in editPaths"
                                    :key="index"
                                    class="flex items-center gap-2"
                                >
                                    <Input
                                        v-model="editPaths[index]"
                                        type="text"
                                        placeholder="/home/user/projects"
                                        class="flex-1 font-mono text-sm"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        @click="openDirectoryPicker(index)"
                                        title="Browse directories"
                                    >
                                        <FolderOpen class="w-4 h-4" />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        @click="removePath(index)"
                                        class="hover:text-red-400"
                                    >
                                        <Trash2 class="w-4 h-4" />
                                    </Button>
                                </div>
                                <Button type="button" variant="outline" size="sm" @click="addPath">
                                    <Plus class="w-4 h-4 mr-2" />
                                    Add path
                                </Button>
                            </div>
                        </div>

                        <Separator class="bg-zinc-800" />

                        <!-- TLD -->
                        <div class="flex flex-col sm:flex-row sm:items-start gap-4 py-6">
                            <div class="sm:w-1/2 space-y-1">
                                <Label class="text-sm font-medium text-white">TLD</Label>
                                <p class="text-xs text-zinc-500">Top-level domain for local projects.</p>
                            </div>
                            <div class="sm:w-1/2">
                                <Input v-model="editTld" type="text" placeholder="test" class="font-mono" />
                            </div>
                        </div>

                        <Separator class="bg-zinc-800" />

                        <!-- Default PHP Version -->
                        <div class="flex flex-col sm:flex-row sm:items-start gap-4 py-6">
                            <div class="sm:w-1/2 space-y-1">
                                <Label class="text-sm font-medium text-white">Default PHP Version</Label>
                                <p class="text-xs text-zinc-500">PHP version used for new projects.</p>
                            </div>
                            <div class="sm:w-1/2">
                                <Select v-model="editPhpVersion">
                                    <SelectTrigger class="w-full">
                                        <SelectValue placeholder="Select PHP version" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="version in availablePhpVersions"
                                            :key="version"
                                            :value="version"
                                        >
                                            PHP {{ version }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <Separator class="bg-zinc-800" />

                        <div class="flex justify-end py-6">
                            <Button
                                type="button"
                                @click="saveConfig"
                                :disabled="configSaving"
                                class="bg-lime-500 hover:bg-lime-600 text-zinc-950"
                            >
                                {{ configSaving ? "Saving..." : "Save Configuration" }}
                            </Button>
                        </div>
                    </template>

                    <Separator class="bg-zinc-800 my-4" />

                    <!-- External Access Section -->
                    <div class="space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4 py-6">
                            <div class="sm:w-1/2 space-y-1">
                                <Label class="text-sm font-medium text-white">External Access</Label>
                                <p class="text-xs text-zinc-500">Enable SSH links for external access.</p>
                            </div>
                            <div class="sm:w-1/2 flex sm:justify-end">
                                <Switch
                                    :checked="externalAccessForm.external_access"
                                    @update:checked="externalAccessForm.external_access = $event"
                                />
                            </div>
                        </div>

                        <div v-if="externalAccessForm.external_access" class="space-y-4">
                            <Separator class="bg-zinc-800" />
                            <div class="flex flex-col sm:flex-row sm:items-start gap-4 py-6">
                                <div class="sm:w-1/2 space-y-1">
                                    <Label class="text-sm font-medium text-white">External Host / IP</Label>
                                    <p class="text-xs text-zinc-500">The hostname or IP address external users will use to connect via SSH.</p>
                                </div>
                                <div class="sm:w-1/2">
                                    <Input
                                        v-model="externalAccessForm.external_host"
                                        type="text"
                                        placeholder="e.g. 192.168.1.100 or myserver.example.com"
                                        class="font-mono"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Save button always visible -->
                        <div class="flex justify-end py-6">
                            <Button
                                type="button"
                                @click="saveExternalAccess"
                                :disabled="externalAccessForm.processing"
                                class="bg-lime-500 hover:bg-lime-600 text-zinc-950"
                            >
                                {{ externalAccessForm.processing ? "Saving..." : "Save External Access" }}
                            </Button>
                        </div>
                    </div>

                    <!-- SSH Keys Section (Desktop only) -->
                    <template v-if="multiEnvironment">
                        <Separator class="bg-zinc-800 my-4" />

                        <div class="py-6">
                            <div class="mb-6">
                                <Label class="text-sm font-medium text-white">SSH Keys</Label>
                                <p class="text-xs text-zinc-500 mt-1">Manage SSH keys for environment provisioning.</p>
                            </div>

                            <div class="space-y-3">
                                <!-- Empty State -->
                                <div v-if="sshKeys.length === 0" class="text-center py-12 text-zinc-500">
                                    <Key class="w-12 h-12 mx-auto mb-3 text-zinc-600" />
                                    <p class="text-sm">No SSH keys configured.</p>
                                </div>

                                <!-- Keys List -->
                                <div
                                    v-for="key in sshKeys"
                                    :key="key.id"
                                    class="flex items-center justify-between rounded-lg border border-zinc-700 bg-zinc-800/50 p-4"
                                >
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-medium text-white">{{ key.name }}</p>
                                            <Badge v-if="key.is_default" class="text-xs bg-lime-400/15 text-lime-400 border-0">
                                                Default
                                            </Badge>
                                        </div>
                                        <p class="text-xs font-mono text-zinc-500 mt-1 truncate max-w-md">{{ truncateKey(key.public_key, 50) }}</p>
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            @click="copyKey(key)"
                                            title="Copy"
                                        >
                                            <Copy class="w-4 h-4" />
                                        </Button>
                                        <Button
                                            v-if="!key.is_default"
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            @click="setDefaultKey(key)"
                                            class="hover:text-lime-400"
                                            title="Set default"
                                        >
                                            <Star class="w-4 h-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            @click="openEditKeyModal(key)"
                                            title="Edit"
                                        >
                                            <Pencil class="w-4 h-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            @click="deleteKey(key)"
                                            class="hover:text-red-400"
                                            title="Delete"
                                        >
                                            <Trash2 class="w-4 h-4" />
                                        </Button>
                                    </div>
                                </div>

                                <Button type="button" variant="outline" size="sm" @click="openAddKeyModal" class="mt-2">
                                    <Plus class="w-4 h-4 mr-2" />
                                    Add SSH Key
                                </Button>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- DNS Tab -->
                <div v-if="activeTab === 'dns'">
                    <DnsSettings :environment-id="environment.id" />
                </div>

                <!-- Templates Tab -->
                <div v-if="activeTab === 'templates'">
                    <div class="mb-6">
                        <Label class="text-sm font-medium text-white">Template Favorites</Label>
                        <p class="text-xs text-zinc-500 mt-1">Manage your favorite project templates.</p>
                    </div>

                    <div class="space-y-3">
                        <!-- Empty State -->
                        <div v-if="templateFavorites.length === 0" class="text-center py-12 text-zinc-500">
                            <FileCode2 class="w-12 h-12 mx-auto mb-3 text-zinc-600" />
                            <p class="text-sm">No template favorites yet.</p>
                        </div>

                        <!-- Templates List -->
                        <div
                            v-for="template in templateFavorites"
                            :key="template.id"
                            class="flex items-center justify-between rounded-lg border border-zinc-700 bg-zinc-800/50 p-4"
                        >
                            <div>
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-white">{{ template.display_name }}</p>
                                    <Badge v-if="template.usage_count > 0" variant="secondary" class="text-xs">
                                        Used {{ template.usage_count }}x
                                    </Badge>
                                </div>
                                <p class="text-xs font-mono text-zinc-500 mt-1">{{ template.repo_url }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    @click="openGitHub(template.repo_url)"
                                    title="Open on GitHub"
                                >
                                    <ExternalLink class="w-4 h-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    @click="openEditTemplateModal(template)"
                                    title="Edit"
                                >
                                    <Pencil class="w-4 h-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    @click="deleteTemplate(template)"
                                    class="hover:text-red-400"
                                    title="Delete"
                                >
                                    <Trash2 class="w-4 h-4" />
                                </Button>
                            </div>
                        </div>

                        <Button type="button" variant="outline" size="sm" @click="openAddTemplateModal" class="mt-2">
                            <Plus class="w-4 h-4 mr-2" />
                            Add Template
                        </Button>
                    </div>
                </div>

                <!-- Advanced Tab -->
                <div v-if="activeTab === 'advanced'">
                    <!-- Desktop Settings (Desktop only) -->
                    <div v-if="multiEnvironment" class="space-y-6 mb-8">
                        <!-- Notifications -->
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4 py-6">
                            <div class="sm:w-1/2 space-y-1">
                                <Label class="text-sm font-medium text-white">Desktop Notifications</Label>
                                <p class="text-xs text-zinc-500">Show system notifications for site events.</p>
                            </div>
                            <div class="sm:w-1/2 flex sm:justify-end">
                                <Switch
                                    :checked="notificationForm.enabled"
                                    @update:checked="toggleNotifications"
                                    :disabled="notificationForm.processing"
                                />
                            </div>
                        </div>

                        <Separator class="bg-zinc-800" />

                        <!-- Menu Bar -->
                        <div class="flex flex-col sm:flex-row sm:items-center gap-4 py-6">
                            <div class="sm:w-1/2 space-y-1">
                                <Label class="text-sm font-medium text-white">Menu Bar Icon</Label>
                                <p class="text-xs text-zinc-500">Show Orbit icon in menu bar.</p>
                            </div>
                            <div class="sm:w-1/2 flex sm:justify-end">
                                <Switch
                                    :checked="menuBarForm.enabled"
                                    @update:checked="toggleMenuBar"
                                    :disabled="menuBarForm.processing"
                                />
                            </div>
                        </div>

                        <Separator class="bg-zinc-800" />
                    </div>

                    <!-- Health Check Section -->
                    <div class="py-6">
                        <div class="flex items-center gap-4 mb-6">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-lime-400/15">
                                <Stethoscope class="h-5 w-5 text-lime-400" />
                            </div>
                            <div class="flex-1">
                                <p v-if="doctorResult" class="text-sm font-medium text-white">
                                    {{ doctorResult.status === "healthy" ? "All Systems Operational" : doctorResult.status === "degraded" ? "Some Warnings" : "Issues Found" }}
                                </p>
                                <p v-else class="text-sm font-medium text-white">Health Check</p>
                                <p class="text-xs text-zinc-500">{{ doctorResult ? "Last checked: Just now" : "Run diagnostics to check health" }}</p>
                            </div>
                            <Button
                                type="button"
                                variant="secondary"
                                size="sm"
                                @click="runDoctor"
                                :disabled="doctorRunning"
                            >
                                <RefreshCw v-if="doctorRunning" class="w-4 h-4 animate-spin mr-2" />
                                <Stethoscope v-else class="w-4 h-4 mr-2" />
                                {{ doctorRunning ? "Running..." : "Run Check" }}
                            </Button>
                        </div>

                        <!-- Error State -->
                        <div v-if="doctorError" class="p-4 bg-red-900/20 border border-red-800 rounded-lg mb-4">
                            <div class="flex items-center gap-2 text-red-400">
                                <XCircle class="w-5 h-5" />
                                <span>{{ doctorError }}</span>
                            </div>
                        </div>

                        <!-- Results -->
                        <div v-if="doctorResult" class="space-y-2">
                            <!-- Summary -->
                            <div class="flex items-center gap-4 mb-4 text-sm">
                                <span class="text-lime-400">{{ doctorResult.summary.passed }} passed</span>
                                <span v-if="doctorResult.summary.warnings > 0" class="text-amber-400">
                                    {{ doctorResult.summary.warnings }} warnings
                                </span>
                                <span v-if="doctorResult.summary.errors > 0" class="text-red-400">
                                    {{ doctorResult.summary.errors }} errors
                                </span>
                            </div>

                            <!-- Individual Checks -->
                            <div
                                v-for="(check, key) in doctorResult.checks"
                                :key="key"
                                class="border border-zinc-700/50 rounded-lg overflow-hidden"
                            >
                                <button
                                    type="button"
                                    @click="toggleCheckExpanded(key as string)"
                                    class="w-full flex items-center justify-between p-3 bg-zinc-800/30 hover:bg-zinc-700/30 transition-colors text-left"
                                >
                                    <div class="flex items-center gap-3">
                                        <CheckCircle2 v-if="check.status === 'ok'" class="w-5 h-5 text-lime-400" />
                                        <AlertTriangleIcon v-else-if="check.status === 'warning'" class="w-5 h-5 text-amber-400" />
                                        <XCircle v-else class="w-5 h-5 text-red-400" />

                                        <div>
                                            <span class="text-white font-medium">{{ checkLabels[key as string] || key }}</span>
                                            <p class="text-sm text-zinc-400 mt-0.5">{{ check.message }}</p>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <Button
                                            v-if="check.status === 'error' && check.details?.can_fix"
                                            @click.stop="fixIssue(key as string)"
                                            :disabled="fixingChecks.has(key as string)"
                                            variant="secondary"
                                            size="sm"
                                        >
                                            <Loader2 v-if="fixingChecks.has(key as string)" class="w-3 h-3 animate-spin mr-1" />
                                            {{ fixingChecks.has(key as string) ? "Fixing..." : "Fix" }}
                                        </Button>

                                        <ChevronDown
                                            v-if="check.details && Object.keys(check.details).length > 0"
                                            :class="[
                                                'w-4 h-4 text-zinc-500 transition-transform',
                                                expandedChecks.has(key as string) ? 'rotate-180' : '',
                                            ]"
                                        />
                                    </div>
                                </button>

                                <div
                                    v-if="expandedChecks.has(key as string) && check.details && Object.keys(check.details).filter((k) => k !== 'can_fix').length > 0"
                                    class="px-4 py-3 bg-zinc-900/50 border-t border-zinc-700/50"
                                >
                                    <dl class="space-y-2 text-sm">
                                        <div
                                            v-for="(value, detailKey) in check.details"
                                            :key="detailKey"
                                            v-show="detailKey !== 'can_fix'"
                                            class="flex"
                                        >
                                            <dt class="text-zinc-500 w-32 flex-shrink-0">{{ detailKey }}</dt>
                                            <dd class="text-zinc-300 font-mono break-all">
                                                <template v-if="Array.isArray(value)">
                                                    <span v-for="(item, i) in value" :key="i" class="block">{{ item }}</span>
                                                </template>
                                                <template v-else-if="typeof value === 'object' && value !== null">
                                                    <pre class="text-xs">{{ JSON.stringify(value, null, 2) }}</pre>
                                                </template>
                                                <template v-else>{{ value }}</template>
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Danger Zone (Desktop only) -->
                    <div v-if="multiEnvironment" class="mt-8">
                        <Separator class="bg-zinc-800 mb-6" />

                        <div class="space-y-4">
                            <div class="rounded-lg border border-red-500/30 bg-red-500/5 p-4">
                                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-medium text-white">Delete Environment</p>
                                        <p class="text-xs text-zinc-500">Permanently delete this environment.</p>
                                    </div>
                                    <Button
                                        v-if="!showDeleteConfirm"
                                        type="button"
                                        variant="destructive"
                                        size="sm"
                                        @click="confirmDelete"
                                    >
                                        Delete
                                    </Button>
                                </div>

                                <div v-if="showDeleteConfirm" class="mt-4 space-y-4">
                                    <div class="flex items-start gap-3 p-3 bg-red-900/20 rounded-lg">
                                        <AlertTriangle class="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
                                        <div>
                                            <p class="text-sm text-red-300 font-medium">Are you sure?</p>
                                            <p class="text-sm text-red-400/80">
                                                Type <strong class="text-red-300">{{ environment.name }}</strong> to confirm.
                                            </p>
                                        </div>
                                    </div>
                                    <Input
                                        v-model="deleteConfirmName"
                                        type="text"
                                        placeholder="Type environment name"
                                        class="w-full"
                                    />
                                    <div class="flex gap-3">
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            size="sm"
                                            @click="deleteEnvironment"
                                            :disabled="deleteConfirmName !== environment.name"
                                        >
                                            Delete
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            @click="cancelDelete"
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Version Info -->
                    <div class="mt-8">
                        <Separator class="bg-zinc-800 mb-6" />

                        <div class="flex flex-col sm:flex-row sm:items-center gap-4 py-6">
                            <div class="sm:w-1/2 space-y-1">
                                <Label class="text-sm font-medium text-white">Orbit Version</Label>
                                <p class="text-xs text-zinc-500">Current version of Orbit.</p>
                            </div>
                            <div class="sm:w-1/2 flex sm:justify-end">
                                <Badge variant="secondary" class="font-mono text-xs">
                                    {{ orbitVersion }}
                                </Badge>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Directory Picker Modal -->
    <Modal :show="showDirectoryPicker" title="Select Directory" @close="closeDirectoryPicker">
        <div class="space-y-4">
            <div class="flex items-center gap-2 text-sm text-zinc-400">
                <FolderOpen class="w-4 h-4" />
                <span class="font-mono">{{ browsingPath }}</span>
            </div>

            <div v-if="browsingLoading" class="py-8 text-center text-zinc-500">
                <Loader2 class="w-6 h-6 animate-spin mx-auto" />
            </div>

            <div v-else-if="browsingError" class="py-8 text-center text-red-400">
                {{ browsingError }}
            </div>

            <div v-else class="max-h-64 overflow-y-auto space-y-1">
                <button
                    v-if="browsingParent"
                    type="button"
                    @click="navigateToDirectory(browsingParent)"
                    class="w-full flex items-center gap-2 px-3 py-2 rounded-md hover:bg-zinc-800 text-left text-sm text-zinc-400"
                >
                    <FolderOpen class="w-4 h-4" />
                    ..
                </button>
                <button
                    v-for="dir in browsingDirectories"
                    :key="dir.path"
                    type="button"
                    @click="navigateToDirectory(dir.path)"
                    class="w-full flex items-center gap-2 px-3 py-2 rounded-md hover:bg-zinc-800 text-left text-sm text-white"
                >
                    <FolderOpen class="w-4 h-4 text-zinc-400" />
                    {{ dir.name }}
                </button>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-800">
                <Button type="button" variant="outline" @click="closeDirectoryPicker">Cancel</Button>
                <Button type="button" @click="selectCurrentDirectory">Select This Directory</Button>
            </div>
        </div>
    </Modal>

    <!-- SSH Key Modal -->
    <Modal :show="showKeyModal" :title="editingKey ? 'Edit SSH Key' : 'Add SSH Key'" @close="closeKeyModal">
        <form @submit.prevent="saveKey" class="space-y-4">
            <div>
                <Label class="text-sm font-medium text-white mb-1.5">Name</Label>
                <Input v-model="keyForm.name" type="text" placeholder="e.g. 1password" />
                <p v-if="keyForm.errors.name" class="mt-1 text-sm text-red-400">{{ keyForm.errors.name }}</p>
            </div>

            <div v-if="!editingKey && Object.keys(availableSshKeys).length > 0">
                <Label class="text-sm font-medium text-white mb-1.5">Import from system</Label>
                <select
                    @change="importKey"
                    class="w-full rounded-md border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white"
                >
                    <option value="">Select a key to import...</option>
                    <option
                        v-for="(keyData, keyName) in availableSshKeys"
                        :key="keyName"
                        :value="keyData.content"
                        :data-name="keyName"
                    >
                        {{ keyName }} ({{ keyData.type }})
                    </option>
                </select>
            </div>

            <div>
                <Label class="text-sm font-medium text-white mb-1.5">Public Key</Label>
                <Textarea
                    v-model="keyForm.public_key"
                    placeholder="ssh-ed25519 AAAA..."
                    rows="4"
                    class="font-mono text-xs"
                />
                <p v-if="keyForm.errors.public_key" class="mt-1 text-sm text-red-400">{{ keyForm.errors.public_key }}</p>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-800">
                <Button type="button" variant="outline" @click="closeKeyModal">Cancel</Button>
                <Button type="submit" :disabled="keyForm.processing">
                    {{ keyForm.processing ? "Saving..." : "Save" }}
                </Button>
            </div>
        </form>
    </Modal>

    <!-- Template Modal -->
    <Modal :show="showTemplateModal" :title="editingTemplate ? 'Edit Template' : 'Add Template'" @close="closeTemplateModal">
        <form @submit.prevent="saveTemplate" class="space-y-4">
            <div>
                <Label class="text-sm font-medium text-white mb-1.5">Repository URL</Label>
                <Input
                    v-model="templateForm.repo_url"
                    type="text"
                    placeholder="owner/repo or https://github.com/owner/repo"
                    @input="onRepoUrlChange"
                />
                <p v-if="templateForm.errors.repo_url" class="mt-1 text-sm text-red-400">{{ templateForm.errors.repo_url }}</p>
            </div>

            <div>
                <Label class="text-sm font-medium text-white mb-1.5">Display Name</Label>
                <Input v-model="templateForm.display_name" type="text" placeholder="My Template" />
                <p v-if="templateForm.errors.display_name" class="mt-1 text-sm text-red-400">{{ templateForm.errors.display_name }}</p>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-zinc-800">
                <Button type="button" variant="outline" @click="closeTemplateModal">Cancel</Button>
                <Button type="submit" :disabled="templateForm.processing">
                    {{ templateForm.processing ? "Saving..." : "Save" }}
                </Button>
            </div>
        </form>
    </Modal>
</template>
