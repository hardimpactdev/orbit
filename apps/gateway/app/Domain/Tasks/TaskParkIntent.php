<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmProviderWorkflowInput;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;

/** Immutable review parking ownership. No provider or guest execution. */
final readonly class TaskParkIntent
{
    public function __construct(private TaskVmProviderWorkflowInput $provider) {}

    public static function requireEligible(Task $group): TaskVm
    {
        $vm = TaskVm::forGroup($group);
        if (! in_array($group->status, [TaskGroupStatus::Running, TaskGroupStatus::Reviewing, TaskGroupStatus::Settling, TaskGroupStatus::WaitingForReview], true)
            || ! $group->pr_url || $group->watched_pr_state !== 'open' || TaskExecutionHold::active($group) || $group->preview
            || array_filter($group->only(TaskAssistance::Fields)) !== [] || ! self::idle($group)
            || ! $vm instanceof TaskVm || ! in_array($vm->state, [TaskVmState::Ready, TaskVmState::Parked], true)) {
            throw new ResourceOperationException('tasks.park_refused', 'Review parking requires an idle external group with an open published PR and no assistance.', 409);
        }
        $workspace = self::requireWorkspace($group);
        if ($workspace->task_workspace_owner_id !== $group->id || $workspace->project_id !== $group->project_id) {
            throw new ResourceOperationException('tasks.park_refused', 'The workspace is not owned by this group and Project.', 409);
        }

        return $vm;
    }

    /**
     * Every subtask has ended or is still `todo` (a prep run leaves its spec subtask `todo`, because the code follows),
     * none asks for assistance, and no turn, check or prep run of the group is running.
     */
    public static function idle(Task $group): bool
    {
        $settled = [TaskStatus::Todo, TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled];

        return ! $group->tasks()->get()->contains(fn (Task $child): bool => array_filter($child->only(TaskAssistance::Fields)) !== [] || ! in_array($child->status, $settled, true))
            && ! Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
                ->whereIn('kind', [OperationKinds::TasksTurn, OperationKinds::TasksCheck, OperationKinds::TasksPrep, OperationKinds::TasksPrepFix, OperationKinds::TasksReviewRound, OperationKinds::TasksBuild])->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }

    /** The group's task VM workspace on the VM's own Node. */
    private static function requireWorkspace(Task $group): Instance
    {
        $instance = $group->taskable;
        $vm = TaskVm::forGroup($group);
        if (! $instance instanceof Instance || ! $vm instanceof TaskVm || $vm->node_id !== $instance->node_id
            || ! in_array($instance->status, [InstanceState::SourceResolved, InstanceState::Active], true)) {
            throw new ResourceOperationException('tasks.no_workspace', 'The group has no task VM workspace.', 409);
        }

        return $instance;
    }

    /** @return array<string,mixed> */
    public function capture(Task $group): array
    {
        $vm = self::requireEligible($group);
        $node = $vm->provider === TaskVmProviderKind::Incus ? Node::query()->findOrFail($vm->host_node_id)
            : Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->firstOrFail();

        return ['group_id' => $group->id, 'project_id' => $group->project_id, 'instance_id' => $group->taskable_id,
            'task_vm_id' => $vm->id, 'guest_node_id' => $vm->node_id, 'node_id' => $node->id, 'name' => $vm->name,
            'provider' => $vm->provider->value, 'provider_id' => $vm->provider_id,
            ...$this->provider->capture($vm)];
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

        return TaskVmProviderWorkflowInput::envelope([...$operation->input, 'eligible' => $eligible]);
    }
}
