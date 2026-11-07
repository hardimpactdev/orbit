<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\Node;
use App\Models\TaskVm;
use App\Models\UpCloudTaskVmImageBuild;

/** Database ownership and public provider intent of the UpCloud sweep, captured once before native execution. */
final readonly class TaskVmSweepWorkflowInput
{
    public function __construct(private TaskVmSettings $settings, private SwampCommandStep $shared, private UpCloudApi $upcloud) {}

    /** @return array<string,mixed> */
    public function capture(Node $node): array
    {
        if ($node->status !== LifecycleStatus::Active || $this->settings->upcloud === null
            || ! $node->roles()->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('task_vm.invalid_config', 'A sweep requires the configured UpCloud provider and the active Gateway.', 409);
        }

        return ['node_id' => $node->id,
            'scope' => ['max_task_vm_id' => TaskVm::query()->max('id') ?? 0, 'live_names' => TaskVm::query()->live()->where('provider', TaskVmProviderKind::UpCloud)->orderBy('id')->pluck('name')->all()],
            'image_cutoff' => now()->subHours(2)->getTimestamp(),
            'running_stamps' => UpCloudTaskVmImageBuild::query()->where('state', TaskVmImageBuildState::Running)
                ->get()->map(static fn (UpCloudTaskVmImageBuild $build): ?string => $build->intent['stamp'] ?? null)->filter()->values()->all(),
            'execution_secrets' => ['upcloud' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-upcloud-token', $this->upcloud->token())]];
    }
}
