<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Arr;

/** Immutable review parking ownership. No provider or guest execution. */
final readonly class TaskParkIntent
{
    public function __construct(private TaskVmSettings $settings, private AgentDelivery $agents) {}

    public static function requireEligible(Task $group): TaskVm
    {
        $vm = TaskVm::forGroup($group);
        if (! in_array($group->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing, TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview], true)
            || ! $group->pr_url || $group->watched_pr_state !== 'open' || TaskExecutionHold::active($group) || $group->preview
            || array_filter($group->only(TaskAssistance::Fields)) !== [] || $group->tasks()->get()->contains(fn (Task $child): bool => array_filter($child->only(TaskAssistance::Fields)) !== []
                || ! in_array($child->status, [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled], true))
            || ! $vm instanceof TaskVm || ! in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true)
            || TaskWorkspaceLeaseRecord::query()->where('instance_id', $group->taskable_id)->whereIn('status', ['held', 'queued'])->exists()) {
            throw new ResourceOperationException('tasks.park_refused', 'Review parking requires an idle external group with an open published PR and no assistance or workspace owner.', 409);
        }
        $workspace = TaskWorkerOperation::requireWorkspace($group);
        if ($workspace->task_workspace_owner_id !== $group->id || $workspace->project_id !== $group->project_id) {
            throw new ResourceOperationException('tasks.park_refused', 'The workspace is not owned by this group and Project.', 409);
        }

        return $vm;
    }

    /** @return array<string,mixed> */
    public function capture(Task $group): array
    {
        $vm = self::requireEligible($group);
        $node = $vm->provider === TaskVmProviderKind::Incus ? Node::query()->findOrFail($vm->host_node_id)
            : Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->firstOrFail();
        $host = $vm->provider === TaskVmProviderKind::Incus ? $this->settings->host($node->id) : null;

        return ['group_id' => $group->id, 'project_id' => $group->project_id, 'instance_id' => $group->taskable_id,
            'task_vm_id' => $vm->id, 'guest_node_id' => $vm->node_id, 'node_id' => $node->id, 'name' => $vm->name,
            'provider' => $vm->provider->value, 'provider_id' => $vm->provider_id, 'agent_sha256' => $this->agents->pin($node),
            'deadline_at' => now()->addSeconds(1500)->timestamp,
            'vm' => $host === null ? null : ['project' => $host->project, 'name' => $vm->name, 'cidr' => $host->cidr],
            ...($host === null ? ['upcloud_token' => ['$secret' => 'upcloud.api', 'id' => $vm->id]] : [])];
    }

    public function owned(Operation $operation): TaskVm
    {
        $input = $operation->input;
        $group = Task::topLevel()->findOrFail($operation->target_id);
        $vm = TaskVm::forGroup($group);
        $workspace = $group->taskable;
        if (! $workspace instanceof Instance || $workspace->task_workspace_owner_id !== $group->id || $workspace->project_id !== $group->project_id
            || ! $vm instanceof TaskVm || $workspace->node_id !== $vm->node_id || $operation->kind !== OperationKinds::TasksPark || $operation->target_type !== OperationKinds::TaskGroupTarget
            || ($input['group_id'] ?? null) !== $group->id || ($input['project_id'] ?? null) !== $group->project_id
            || ($input['instance_id'] ?? null) !== $group->taskable_id || ($input['task_vm_id'] ?? null) !== $vm->id
            || ($input['guest_node_id'] ?? null) !== $vm->node_id || ($input['name'] ?? null) !== $vm->name
            || ($input['provider'] ?? null) !== $vm->provider->value || ($input['provider_id'] ?? null) !== $vm->provider_id
            || ($input['node_id'] ?? null) !== $operation->node_id
            || ($vm->provider === TaskVmProviderKind::Incus && $vm->host_node_id !== $operation->node_id)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The park intent no longer owns its admitted group, workspace and VM.', 422);
        }

        return $vm;
    }

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $this->owned($operation);

        $eligible = true;
        try {
            self::requireEligible(Task::topLevel()->findOrFail($operation->target_id));
        } catch (ResourceOperationException) {
            $eligible = false;
        }

        return [...Arr::except($operation->input, ['upcloud_token']), 'eligible' => $eligible];
    }
}
