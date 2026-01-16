import { ref, onUnmounted, type Ref } from 'vue';
import { getEcho, disconnectEcho, type ReverbConfig } from '@/echo';
import type Echo from 'laravel-echo';

export type ProvisionStatus =
    | 'creating_repo'
    | 'cloning'
    | 'setting_up'
    | 'installing_composer'
    | 'installing_npm'
    | 'building'
    | 'finalizing'
    | 'ready'
    | 'failed'
    | 'provisioning';

export type DeletionStatus =
    | 'deleting'
    | 'removing_orchestrator'
    | 'removing_vibekanban'
    | 'removing_linear'
    | 'removing_files'
    | 'deleted'
    | 'delete_failed';

export interface ProvisionEvent {
    slug: string;
    status: ProvisionStatus;
    error?: string | null;
    project_id?: number | null;
    timestamp: string;
}

export interface DeletionEvent {
    slug: string;
    status: DeletionStatus;
    error?: string | null;
    timestamp: string;
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

interface ReverbConfigResponse {
    success: boolean;
    enabled?: boolean;
    host?: string;
    port?: number;
    scheme?: string;
    app_key?: string;
    error?: string;
}

/**
 * Composable for listening to project provisioning events via WebSocket.
 * Connects to the standalone Reverb service configured in the environment.
 */
export function useProjectProvisioning(environmentId: number) {
    const provisioningProjects: Ref<Map<string, ProvisioningProject>> = ref(new Map());
    const deletingProjects: Ref<Map<string, DeletingProject>> = ref(new Map());
    const isConnected = ref(false);
    const connectionError = ref<string | null>(null);

    // Reactive counters that increment on terminal events - easier to watch than Maps
    const projectReadyCount = ref(0);
    const projectDeletedCount = ref(0);

    let echo: Echo<'reverb'> | null = null;

    async function connect() {
        try {
            // Fetch Reverb config from the environment
            const response = await fetch(`/environments/${environmentId}/reverb-config`);
            const result: ReverbConfigResponse = await response.json();

            if (!result.success) {
                connectionError.value = result.error || 'Failed to get Reverb config';
                return;
            }

            if (!result.enabled) {
                connectionError.value = 'Reverb service not enabled';
                return;
            }

            const config: ReverbConfig = {
                host: result.host!,
                port: result.port!,
                scheme: result.scheme as 'http' | 'https',
                key: result.app_key!,
            };

            echo = getEcho(config);

            // Listen to the provisioning channel for all provision events
            echo.channel('provisioning')
                .listen('.project.provision.status', (event: ProvisionEvent) => {
                    handleProvisionEvent(event);
                })
                .listen('.project.deletion.status', (event: DeletionEvent) => {
                    handleDeletionEvent(event);
                });

            isConnected.value = true;
            connectionError.value = null;
        } catch (e) {
            connectionError.value = e instanceof Error ? e.message : 'Failed to connect';
            isConnected.value = false;
        }
    }

    // Track which slugs we've already processed to prevent duplicate handling
    const processedEvents = new Map<string, string>();

    function handleProvisionEvent(event: ProvisionEvent) {
        // Deduplicate events (we listen on multiple channels)
        if (processedEvents.get(event.slug) === event.status) {
            return;
        }
        processedEvents.set(event.slug, event.status);

        const existing = provisioningProjects.value.get(event.slug);

        // Always use .set() to ensure Vue reactivity triggers properly
        provisioningProjects.value.set(event.slug, {
            slug: event.slug,
            status: event.status,
            error: event.error,
            projectId: event.project_id ?? existing?.projectId,
        });

        // Remove from tracking if terminal state
        if (event.status === 'ready' || event.status === 'failed') {
            // Increment counter for watchers
            if (event.status === 'ready') {
                projectReadyCount.value++;
            }
            // Keep in list longer so UI can show final state
            setTimeout(() => {
                provisioningProjects.value.delete(event.slug);
                processedEvents.delete(event.slug);
            }, 15000);
        }
    }

    // Track deletion events separately
    const processedDeletionEvents = new Map<string, string>();

    function handleDeletionEvent(event: DeletionEvent) {
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
            // Increment counter for watchers
            if (event.status === 'deleted') {
                projectDeletedCount.value++;
            }
            setTimeout(() => {
                deletingProjects.value.delete(event.slug);
                processedDeletionEvents.delete(event.slug);
            }, 2000);
        }
    }

    function trackDeletion(slug: string) {
        if (!deletingProjects.value.has(slug)) {
            deletingProjects.value.set(slug, {
                slug,
                status: 'deleting',
            });
        }

        // Subscribe to project-specific channel for deletion events
        if (echo) {
            echo.channel(`project.${slug}`)
                .listen('.project.deletion.status', (event: DeletionEvent) => {
                    handleDeletionEvent(event);
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
                status: 'provisioning',
            });
        }

        // Also subscribe to project-specific channel
        if (echo) {
            echo.channel(`project.${slug}`)
                .listen('.project.provision.status', (event: ProvisionEvent) => {
                    handleProvisionEvent(event);
                });
        }
    }

    function getProjectStatus(slug: string): ProvisioningProject | undefined {
        return provisioningProjects.value.get(slug);
    }

    function disconnect() {
        disconnectEcho();
        echo = null;
        isConnected.value = false;
    }

    onUnmounted(() => {
        disconnect();
    });

    return {
        provisioningProjects,
        deletingProjects,
        isConnected,
        connectionError,
        projectReadyCount,
        projectDeletedCount,
        connect,
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
