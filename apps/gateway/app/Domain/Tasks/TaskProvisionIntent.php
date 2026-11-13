<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Domain\TaskVms\TaskVmSlotRuns;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One Tasks admission takes a slot and mints its identity (docs/cli/task/start.mmd), then records the intent of its task start: a copy
 * of the host's base VM snapshot with that identity, the hub peer, the converge and the task branch. No child Operations.
 */
final readonly class TaskProvisionIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency,
        private TaskStartCommit $startCommit, private TaskVmSlotPool $slots,
        private TaskVmSlotRuns $runs) {}

    /** A proof starts its group at the commit it proves; any other group starts at the newest green commit of the default branch. */
    public function start(Task $group, string $caller, ?string $request, ?string $commit = null): Operation
    {
        return $this->admit($group, $commit, 'tasks.provision', static fn (array $input): array => $input, $caller, $request);
    }

    /**
     * Claims a slot in one admission transaction and records the intent as the Operation of `$kind`. A kind that runs the claim as one step
     * of a larger run, such as `tasks.prep`, adds its own intent through `$extend`, which receives the provision intent, the group, its
     * workspace Instance and its slot, and returns the whole input of the Operation; the node of the Operation stays the slot's host.
     * Only `proof.command` with persisted authenticated CI eligibility uses the CI pool; task work uses the task pool.
     *
     * @param  Closure(array<string,mixed>, Task, Instance, TaskVm): array<string,mixed>  $extend
     * @param  array<string,mixed>  $capacityRequest
     */
    public function admit(Task $group, ?string $commit, string $kind, Closure $extend, string $caller, ?string $request, bool $ciPool = false, array $capacityRequest = []): Operation
    {
        if (($replay = $this->idempotency->replay($kind)) !== null) {
            return $replay;
        }
        $this->assertProvisionable($group);
        $startCommit = $commit ?? $this->startCommit->resolve($group->project()->firstOrFail());
        $capture = function () use ($group, $startCommit, $extend, $kind, $ciPool): array {
            $group = Task::topLevel()->with('project')->lockForUpdate()->findOrFail($group->id);
            $this->assertProvisionable($group);
            $input = $this->capture($group, $startCommit, $kind === 'proof.command' && $ciPool);

            return $extend($input, $group, Instance::query()->findOrFail(ValidatedData::integer($input['instance_id'])), TaskVm::query()->findOrFail(ValidatedData::integer($input['task_vm_id'])));
        };
        if ($kind === 'proof.command' && $ciPool) {
            return DB::transaction(fn (): Operation => $this->start->execute($kind, 'task_group', $group->id,
                ValidatedData::integer(($input = $capture())['node_id']), $input, $caller, $request));
        }

        return app(TaskVmAdmission::class)->start($group, $kind, ['commit' => $startCommit, ...$capacityRequest], $capture, $caller, $request);
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
