<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Processes\ProcessRuntimeStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Workspace;

class ProcessListPayload
{
    public function __construct(
        private readonly ProcessOwnerContextResolver $contexts,
        private readonly ProcessRuntimeDriverRegistry $runtimeDrivers,
        private readonly ProcessServiceMetadataPayload $serviceMetadata,
    ) {}

    /**
     * @return array{context: array{node: string, app: string|null, instance: string|null, workspace: string|null}, processes: list<array<string, mixed>>}
     */
    public function forContext(
        ?string $nodeName,
        ?string $appName,
        ?string $workspaceName,
        ?Node $caller = null,
        ?string $appHostname = null,
    ): array {
        $context = $this->contexts->resolveVisible(
            nodeName: $nodeName,
            appName: $appName,
            workspaceName: $workspaceName,
            caller: $caller,
            permission: 'process:read',
            allowSingleVisibleAppDefault: true,
            appHostname: $appHostname,
        );
        $app = $context->runtimeApp();
        $processes = $context->lifecycleProcesses(null);

        return [
            'context' => $context->payloadContext(),
            'processes' => array_values(
                $processes->map(function (Process $process) use ($context, $app): array {
                    $workspace = $context->runtimeWorkspaceFor($process);
                    $driver = $this->runtimeDrivers->forProcess($process);
                    $lastEvent = $this->lastEventModel($process, $workspace, $context->instance);
                    $status = ProcessRuntimeStatus::fromEventType($lastEvent?->event);

                    return [
                        'node' => $context->node->name,
                        'app' => $context->app?->name,
                        'instance' => $context->instance?->name,
                        'workspace' => $workspace?->name,
                        'key' => $process->name,
                        'label' => $process->label,
                        // Deprecated compatibility alias; new consumers use key + label.
                        'name' => $process->name,
                        'command' => $process->command,
                        'restart_policy' => $process->restart_policy->value,
                        'crash_notification' => $process->crash_notification->value,
                        'runtime' => $process->runtime->value,
                        'tool' => $process->tool,
                        'service' => $this->serviceMetadata->forProcess($process),
                        'runtime_unit' => $driver->runtimeUnitName($app, $process, $workspace),
                        'status' => $status->value,
                        'last_event' => $lastEvent instanceof ProcessEvent
                            ? [
                                'id' => $lastEvent->id,
                                'type' => $lastEvent->event->value,
                            ]
                            : null,
                    ];
                })
                    ->all(),
            ),
        ];
    }

    private function lastEventModel(
        Process $process,
        ?Workspace $workspace,
        ?Instance $instance,
    ): ?ProcessEvent {
        $query = ProcessEvent::query()
            ->where('process_id', $process->id)
            ->where('instance_id', $instance?->id);

        if ($workspace instanceof Workspace) {
            $query->where('workspace_id', $workspace->id);
        }

        if ($workspace === null) {
            $query->whereNull('workspace_id');
        }

        $event = $query
            ->latest('recorded_at')
            ->latest('id')
            ->first();

        return $event instanceof ProcessEvent ? $event : null;
    }
}
