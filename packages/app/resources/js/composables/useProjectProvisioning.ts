import { useConnectionStatus } from '@laravel/echo-vue';
import { usePage } from '@inertiajs/vue3';
import { ref, computed, type Ref } from 'vue';

export type ProvisionStatus =
    | 'queued'
    | 'provisioning'
    | 'validating_package'
    | 'creating_project'
    | 'forking'
    | 'creating_repo'
    | 'cloning'
    | 'setting_up'
    | 'installing_composer'
    | 'installing_npm'
    | 'building'
    | 'finalizing'
    | 'ready'
    | 'failed';

export type DeletionStatus =
    | 'deleting'
    | 'removing_files'
    | 'deleted'
    | 'delete_failed';

export interface ProvisionEvent {
    slug: string;
    status: ProvisionStatus;
    error?: string | null;
    project_id?: number | null;
    timestamp?: string;
}

export interface DeletionEvent {
    slug: string;
    status: DeletionStatus;
    error?: string | null;
    timestamp?: string;
}

export interface ProvisioningProject {
    slug: string;
    status: ProvisionStatus;
    error?: string | null;
    projectId?: number | null;
}

export interface DeletingProject {
    slug: string;
    status: DeletionStatus;
    error?: string | null;
}

interface ReverbProps {
    enabled?: boolean;
}

// Singleton state - persists across component re-mounts during Inertia navigation
// This ensures WebSocket events received during navigation aren't lost
const provisioningProjects: Ref<Map<string, ProvisioningProject>> = ref(new Map());
const deletingProjects: Ref<Map<string, DeletingProject>> = ref(new Map());
const projectReadyCount = ref(0);
const projectDeletedCount = ref(0);
const processedEvents = new Map<string, string>();
const processedDeletionEvents = new Map<string, string>();

/**
 * Global event handler for provisioning events - called from app.ts
 * This bypasses useEchoPublic's lifecycle hooks to ensure events are never missed
 */
export function globalProvisioningHandler(event: ProvisionEvent | DeletionEvent) {
    if ('status' in event) {
        // Check if it's a deletion event
        if (['deleting', 'removing_files', 'deleted', 'delete_failed'].includes(event.status)) {
            handleGlobalDeletionEvent(event as DeletionEvent);
        } else {
            handleGlobalProvisionEvent(event as ProvisionEvent);
        }
    }
}

function handleGlobalProvisionEvent(event: ProvisionEvent) {
    // Deduplicate events
    if (processedEvents.get(event.slug) === event.status) {
        return;
    }
    processedEvents.set(event.slug, event.status);

    const existing = provisioningProjects.value.get(event.slug);
    provisioningProjects.value.set(event.slug, {
        slug: event.slug,
        status: event.status,
        error: event.error,
        projectId: event.project_id ?? existing?.projectId,
    });

    // Remove from tracking if terminal state
    if (event.status === 'ready' || event.status === 'failed') {
        if (event.status === 'ready') {
            projectReadyCount.value++;
        }
        setTimeout(() => {
            provisioningProjects.value.delete(event.slug);
            processedEvents.delete(event.slug);
        }, 15000);
    }
}

function handleGlobalDeletionEvent(event: DeletionEvent) {
    // Deduplicate events
    if (processedDeletionEvents.get(event.slug) === event.status) {
        return;
    }
    processedDeletionEvents.set(event.slug, event.status);

    deletingProjects.value.set(event.slug, {
        slug: event.slug,
        status: event.status,
        error: event.error,
    });

    // Remove from tracking if terminal state
    if (event.status === 'deleted' || event.status === 'delete_failed') {
        if (event.status === 'deleted') {
            projectDeletedCount.value++;
        }
        setTimeout(() => {
            deletingProjects.value.delete(event.slug);
            processedDeletionEvents.delete(event.slug);
        }, 2000);
    }
}

/**
 * Composable for listening to project provisioning events via WebSocket.
 * Uses the globally configured Echo connection.
 * State is singleton - persists across navigations.
 */
export function useProjectProvisioning() {
    const connectionStatus = useConnectionStatus();
    const page = usePage();

    const reverbEnabled = computed(
        () => Boolean((page.props.reverb as ReverbProps | undefined)?.enabled),
    );

    const isConfigured = computed(() => reverbEnabled.value);

    const isConnected = computed(() =>
        reverbEnabled.value && connectionStatus.value === 'connected',
    );
    const connectionError = computed(() =>
        reverbEnabled.value && connectionStatus.value === 'failed'
            ? 'Reverb connection unavailable'
            : null,
    );

    // WebSocket listeners are now set up globally in app.ts to avoid
    // lifecycle issues with useEchoPublic during Inertia navigation

    function trackDeletion(slug: string) {
        if (!deletingProjects.value.has(slug)) {
            deletingProjects.value.set(slug, {
                slug,
                status: 'deleting',
            });
        }
    }

    function getDeletionStatus(slug: string): DeletingProject | undefined {
        return deletingProjects.value.get(slug);
    }

    function markDeletionComplete(slug: string) {
        deletingProjects.value.set(slug, {
            slug,
            status: 'deleted',
        });
        projectDeletedCount.value++;
        setTimeout(() => {
            deletingProjects.value.delete(slug);
        }, 2000);
    }

    function markDeletionFailed(slug: string, error?: string) {
        deletingProjects.value.set(slug, {
            slug,
            status: 'delete_failed',
            error,
        });
    }

    function clearDeletion(slug: string) {
        deletingProjects.value.delete(slug);
        processedDeletionEvents.delete(slug);
    }

    function trackProject(slug: string) {
        if (!provisioningProjects.value.has(slug)) {
            provisioningProjects.value.set(slug, {
                slug,
                status: 'queued',
            });
        }
        // Events are now handled globally in app.ts
    }

    function getProjectStatus(slug: string): ProvisioningProject | undefined {
        return provisioningProjects.value.get(slug);
    }

    function disconnect() {
        // no-op: channels are cleaned up by the echo-vue composables
    }

    return {
        provisioningProjects,
        deletingProjects,
        isConnected,
        connectionError,
        isConfigured,
        projectReadyCount,
        projectDeletedCount,
        connect: () => undefined,
        disconnect,
        trackProject,
        getProjectStatus,
        trackDeletion,
        getDeletionStatus,
        markDeletionComplete,
        markDeletionFailed,
        clearDeletion,
    };
}
