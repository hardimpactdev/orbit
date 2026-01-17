<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Server, Loader2, AlertCircle, Trash2, Pencil, Eye } from 'lucide-vue-next';
import { Button, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell, TableEmpty } from '@hardimpactdev/craft-ui';

interface Environment {
    id: number;
    name: string;
    host: string;
    user: string;
    port: number;
    is_local: boolean;
    status: string | null;
    provisioning_step: number | null;
    provisioning_total_steps: number | null;
    last_connected_at: string | null;
}

const props = defineProps<{
    environments: Environment[];
    hasLocalEnvironment: boolean;
}>();

// TLD tracking
const tldMap = ref<Map<number, string>>(new Map());
const conflictingTlds = ref<Set<string>>(new Set());

const loadTld = async (environmentId: number) => {
    try {
        const response = await fetch(`/environments/${environmentId}/config`);
        const result = await response.json();

        if (result.success && result.data) {
            const tld = result.data.tld || 'test';
            tldMap.value.set(environmentId, tld);
            checkForConflicts();
        }
    } catch (error) {
        console.error(`Failed to load TLD for environment ${environmentId}:`, error);
    }
};

const checkForConflicts = () => {
    const tldCounts = new Map<string, number>();

    tldMap.value.forEach((tld) => {
        tldCounts.set(tld, (tldCounts.get(tld) || 0) + 1);
    });

    const conflicts = new Set<string>();
    tldCounts.forEach((count, tld) => {
        if (count > 1) {
            conflicts.add(tld);
        }
    });
    conflictingTlds.value = conflicts;
};

const getTld = (environmentId: number) => tldMap.value.get(environmentId);
const isConflict = (environmentId: number) => {
    const tld = tldMap.value.get(environmentId);
    return tld ? conflictingTlds.value.has(tld) : false;
};

const getConflictCount = (environmentId: number) => {
    const tld = tldMap.value.get(environmentId);
    if (!tld) return 0;

    let count = 0;
    tldMap.value.forEach((t) => {
        if (t === tld) count++;
    });
    return count;
};

const deleteEnvironment = (environment: Environment) => {
    if (confirm('Are you sure you want to delete this environment?')) {
        router.delete(`/environments/${environment.id}`);
    }
};

const formatLastConnected = (dateString: string | null) => {
    if (!dateString) return 'Never';

    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
    if (diffDays < 30) return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
    return date.toLocaleDateString();
};

onMounted(() => {
    props.environments.forEach((environment) => loadTld(environment.id));
});
</script>

<template>
    <Head title="Environments" />

    <div>
        <div class="flex justify-between items-center mb-8">
            <Heading title="Environments" />
            <Button v-if="$page.props.multi_environment" as-child>
                <Link href="/environments/create">
                    Add environment
                </Link>
            </Button>
        </div>

        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Host</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Last connected</TableHead>
                    <TableHead class="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="environment in environments" :key="environment.id">
                    <TableCell class="whitespace-nowrap">
                        <div class="flex items-center">
                            <span class="font-medium text-foreground">{{ environment.name }}</span>
                            <!-- TLD Badge -->
                            <Badge
                                v-if="getTld(environment.id)"
                                class="ml-2 font-mono"
                                :variant="isConflict(environment.id) ? 'destructive' : 'secondary'"
                                :title="
                                    isConflict(environment.id)
                                        ? `Warning: ${getConflictCount(environment.id)} environments use .${getTld(environment.id)} - this may cause DNS conflicts`
                                        : ''
                                "
                            >
                                .{{ getTld(environment.id) }}
                            </Badge>
                            <Badge v-if="environment.is_local" class="ml-2 bg-lime-400/10 text-lime-300 border-lime-400/20">
                                Local
                            </Badge>
                        </div>
                    </TableCell>
                    <TableCell class="whitespace-nowrap text-muted-foreground">
                        <template v-if="environment.is_local">localhost</template>
                        <template v-else
                            >{{ environment.user }}@{{ environment.host }}:{{
                                environment.port
                            }}</template
                        >
                    </TableCell>
                    <TableCell class="whitespace-nowrap">
                        <!-- Provisioning Status -->
                        <span
                            v-if="environment.status === 'provisioning'"
                            class="inline-flex items-center text-sm text-blue-400"
                        >
                            <Loader2 class="w-3.5 h-3.5 mr-2 animate-spin" />
                            Provisioning ({{ environment.provisioning_step ?? 0 }}/{{
                                environment.provisioning_total_steps ?? 14
                            }})
                        </span>
                        <!-- Error Status -->
                        <span
                            v-else-if="environment.status === 'error'"
                            class="inline-flex items-center text-sm text-red-400"
                        >
                            <AlertCircle class="w-3.5 h-3.5 mr-2" />
                            Error
                        </span>
                        <!-- Active Status -->
                        <span v-else class="inline-flex items-center text-sm text-lime-400">
                            <span class="w-2 h-2 rounded-full bg-lime-400 mr-2"></span>
                            Active
                        </span>
                    </TableCell>
                    <TableCell class="whitespace-nowrap text-muted-foreground">
                        {{ formatLastConnected(environment.last_connected_at) }}
                    </TableCell>
                    <TableCell class="whitespace-nowrap text-right">
                        <div class="flex items-center justify-end gap-1">
                            <Button as-child variant="ghost" size="icon-sm">
                                <Link
                                    :href="`/environments/${environment.id}`"
                                    title="View"
                                >
                                    <Eye class="w-4 h-4" />
                                </Link>
                            </Button>
                            <Button v-if="$page.props.multi_environment" as-child variant="ghost" size="icon-sm">
                                <Link
                                    :href="`/environments/${environment.id}/edit`"
                                    title="Edit"
                                >
                                    <Pencil class="w-4 h-4" />
                                </Link>
                            </Button>
                            <Button
                                v-if="$page.props.multi_environment"
                                @click="deleteEnvironment(environment)"
                                variant="ghost"
                                size="icon-sm"
                                class="text-muted-foreground hover:text-red-400"
                                title="Delete"
                            >
                                <Trash2 class="w-4 h-4" />
                            </Button>
                        </div>
                    </TableCell>
                </TableRow>
                <!-- Empty State -->
                <TableEmpty v-if="environments.length === 0" :colspan="5">
                    <Server class="w-12 h-12 mx-auto text-zinc-600 mb-4" />
                    <p class="text-zinc-400 mb-2">No environments configured yet</p>
                    <p class="text-sm text-zinc-500">
                        Add a local or external environment to get started
                    </p>
                </TableEmpty>
            </TableBody>
        </Table>
    </div>
</template>
