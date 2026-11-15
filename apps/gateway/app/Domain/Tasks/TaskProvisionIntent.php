<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Str;

/**
 * Where a task starts (docs/cli/task/start.mmd): a copy of its Project's default Instance. A Project whose default is no container
 * gets the refusal of the removed task VM runtime. The VM claim below stays only for the task VM kinds that still read it.
 */
final readonly class TaskProvisionIntent
{
    public function __construct(private TaskVmSlotPool $slots, private TaskVmSlotRuns $runs) {}

    /** A task starts only as a copy of its Project's default Instance. */
    public function start(Task $group, string $caller, ?string $request): Operation
    {
        InstancePlacement::taskCopy($group->project()->firstOrFail());

        return app(TaskCopyStart::class)->start($group, $caller, $request);
    }

    /**
     * The task VM admission is removed: the proof VM branch that still calls it gets the refusal of the removed runtime.
     *
     * @param  Closure(array<string,mixed>, Task, Instance, TaskVm): array<string,mixed>  $extend
     * @param  array<string,mixed>  $capacityRequest
     */
    public function admit(Task $group, ?string $commit, string $kind, Closure $extend, string $caller, ?string $request, bool $ciPool = false, array $capacityRequest = []): never
    {
        throw TaskVmRemoved::refusal();
    }

    /** Claims a fresh workspace. Re-entry uses the published branch after the VM was deleted. Call in the admission transaction.
     * @return array<string,mixed>
     */
    public function capture(Task $group, string $commit, bool $ci = false): array
    {
        InstancePlacement::taskWorkspace($group->project()->firstOrFail());
        $slot = $this->slots->take($group, proof: $ci);
        $branch = TaskWorkspaceName::for($group);
        $claim = $this->runs->claim($slot, $branch, $commit);
        $instance = $this->workspace($slot, $group, $branch, ValidatedData::string(ValidatedData::object($claim['intent']['slot'])['checkout']));
        $input = ['group_id' => $group->id, 'instance_id' => $instance->id, 'task_vm_id' => $slot->id, 'node_id' => (int) $slot->host_node_id,
            'project_id' => $group->project_id,
            'workspace' => ['checkout_path' => $instance->checkout_path, 'user' => 'orbit', 'repository' => $group->project->repository_url,
                'branch' => $branch, 'start_commit' => $commit],
            'intent' => $claim['intent'], 'execution_secrets' => $claim['execution_secrets']];
        $group->taskable()->associate($instance);
        $group->fill(['status' => TaskGroupStatus::Reserved, 'reserved_at' => now(), 'capacity_wait_reason' => null])->save();
        $group->setRelation('taskable', $instance);

        return $input;
    }

    /** The workspace Instance of the new slot's Node, at the warm checkout the base carries. The release deletes it with the Node. */
    private function workspace(TaskVm $slot, Task $group, string $branch, string $checkout): Instance
    {
        $instance = new Instance(['project_id' => $group->project_id, 'node_id' => $slot->node_id, 'source_layout' => InstanceSourceLayout::Checkout,
            'checkout_path' => $checkout, 'root' => null, 'task_workspace_routed' => false]);
        $instance->fill(['name' => TaskWorkspaceName::for($group), 'branch_override' => $branch, 'task_workspace_owner_id' => $group->id,
            'source_prepare_id' => (string) Str::uuid(), 'status' => InstanceState::Reserved, 'first_setup_pending' => false,
            'failed_step' => null, 'error_code' => null])->save();

        return $instance;
    }

    public function assertProvisionable(Task $group): void
    {
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
        if ($group->status === TaskGroupStatus::Backlog) {
            throw new ResourceOperationException('tasks.not_todo', 'Provision requires a todo task group.', 409);
        }
        if ($group->status !== TaskGroupStatus::Todo || $group->taskable_id !== null) {
            throw TaskGroupGuard::alreadyClaimed();
        }
    }
}
