<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\Node;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Models\UpCloudTaskVmImageBuild;

/** Database ownership and public provider intent captured once before native execution. */
final readonly class TaskVmSweepWorkflowInput
{
    public function __construct(private TaskVmSettings $settings, private SwampSshExecutionInput $ssh,
        private SwampCommandStep $shared, private UpCloudApi $upcloud) {}

    /** @return array<string,mixed> */
    public function capture(Node $node, string $provider): array
    {
        if ($node->status !== LifecycleStatus::Active || ! in_array($provider, ['incus', 'upcloud'], true)
            || ($provider === 'upcloud' && ($this->settings->upcloud === null
                || ! $node->roles()->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active)->exists()))) {
            throw new ResourceOperationException('task_vm.invalid_config', 'A sweep requires its configured active host or Gateway provider.', 409);
        }
        $host = $provider === 'incus' ? $this->settings->host($node->id) : null;
        $live = TaskVm::query()->live()->where('provider', $provider);
        if ($host !== null) {
            $live->where('host_node_id', $node->id);
        }
        $builds = TaskVmImageBuild::query()->where('host_node_id', $node->id);
        $retired = [];
        if ($host !== null) {
            foreach ((clone $builds)->where('state', '!=', TaskVmImageBuildState::Running)->orderBy('id')->get() as $build) {
                if ($build->builder_vm_name !== null && TaskVmHostAudit::claimBuildCleanup($node, $build->builder_vm_name)) {
                    $retired[] = $build->id;
                }
            }
        }

        $maxTaskVmId = TaskVm::query()->max('id') ?? 0;
        $scope = ['max_task_vm_id' => $maxTaskVmId, 'live_names' => $live->orderBy('id')->pluck('name')->all()];
        if ($host !== null) {
            $scope += ['project' => $host->project, 'pool' => $host->pool, 'operator_owned' => $host->operatorOwned,
                'max_build_id' => TaskVmImageBuild::query()->max('id') ?? 0,
                'recorded_build_ids' => (clone $builds)->orderBy('id')->pluck('id')->all(), 'retired_build_ids' => $retired,
                'protected_build_ids' => (clone $builds)->where(fn ($q) => $q->where('state', TaskVmImageBuildState::Running)
                    ->orWhere(fn ($q) => $q->where('state', TaskVmImageBuildState::Failed)->whereNull('cleanup_started_at')
                        ->where('updated_at', '>', now()->subHours(TaskVmHostAudit::FailedBuildHours))))->orderBy('id')->pluck('id')->all()];
        }

        return ['node_id' => $node->id, 'provider' => $provider,
            ...($host === null ? [] : ['ssh_execution' => $this->ssh->forNode($node)]), 'scope' => $scope,
            'ci_runner' => $node->roles()->where('role', RoleName::CiRunner)->where('status', LifecycleStatus::Active)->exists(),
            'stray_vm_ids' => $host === null ? [] : array_map(static fn (TaskVm $vm): int => $vm->id, TaskVmHostAudit::strayRecords($node)),
            'image_cutoff' => now()->subHours(2)->getTimestamp(),
            'running_stamps' => UpCloudTaskVmImageBuild::query()->where('state', TaskVmImageBuildState::Running)
                ->get()->map(static fn (UpCloudTaskVmImageBuild $build): ?string => $build->intent['stamp'] ?? null)->filter()->values()->all(),
            'execution_secrets' => $host !== null ? [] : ['upcloud' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-upcloud-token', $this->upcloud->token())]];
    }
}
