<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Nodes\NodeRemovalRecords;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Tasks\TaskAssistance;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the release of a task VM (docs/cli/task/vm-release.mmd), as its own run or as the last step of a cancel (docs/cli/task/cancel.mmd).
 * A release that passed deleted the VM, its seed clone and its hub peer: its workspace Instance, its Node and its identity go, and the slot
 * is free. One that did not pass leaves the VM `failed`; the sweep releases it again.
 */
final readonly class TaskVmSlotReleaseProjection implements DirectOperationProjection, DirectOperationStartRefusal
{
    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);

        // A release takes no secret; the submission sends the empty map as an object.
        return ['intent' => ValidatedData::object($input['intent']), 'execution_secrets' => ValidatedData::object($input['execution_secrets'])];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($operation->result ?? []));
            }
            $slot = TaskVm::query()->lockForUpdate()->findOrFail($operation->target_id);
            $passed = ($data['status'] ?? null) === 'succeeded';
            $this->record($slot, $passed, $passed ? null : (ValidatedData::nullableString($data['error_code'] ?? null) ?? 'task_vm.slot_release_failed'));
            $result = ['slot' => $slot->name, 'released' => $passed];

            return app(DirectOperation::class)->project($operation, [...$data, 'result' => $result], $result);
        });
    }

    /** A refused start never ran: the VM is still there, so it is failed and the sweep releases it again. */
    public function refused(Operation $operation): void
    {
        $slot = TaskVm::query()->lockForUpdate()->find($operation->target_id);
        if ($slot instanceof TaskVm) {
            $this->record($slot, false, $operation->error_code ?? 'task_vm.slot_release_failed');
        }
    }

    /** The slot after its release: destroyed with its Node deleted, or failed. */
    public function record(TaskVm $slot, bool $passed, ?string $errorCode): void
    {
        if (! $passed) {
            $slot->update(['state' => TaskVmState::Failed, 'group_id' => null, 'error_code' => $errorCode, 'error_message' => 'The task VM release did not pass.']);

            return;
        }
        // The VM and its hub peer are gone: its workspace Instance, its Node and the identity go with them, and the slot is free.
        $node = $slot->node;
        if ($node instanceof Node) {
            // A group whose workspace goes no longer points at it, so its cancel finds nothing to own and only closes it. A removal failure
            // it asked for assistance about has no cause left: a group owns one workspace, and this was it. Only that kind of request ends.
            Instance::query()->where('node_id', $node->id)->get()->each(static function (Instance $instance): void {
                $groups = Task::topLevel()->whereMorphedTo('taskable', $instance)->pluck('id')->push($instance->task_workspace_owner_id)->filter()->unique();
                Task::topLevel()->whereMorphedTo('taskable', $instance)->update(['taskable_type' => null, 'taskable_id' => null]);
                $instance->delete();
                Task::topLevel()->whereKey($groups)->removalFailure()->get()
                    ->each(static fn (Task $group): bool => TaskAssistance::apply($group));
            });
            app(NodeRemovalRecords::class)->remove(['node_id' => $node->id, 'name' => $node->name, 'task_vm_id' => $slot->id, 'route_ids' => []], []);
        }
        $slot->update(['state' => TaskVmState::Destroyed, 'destroyed_at' => now(), 'group_id' => null, 'node_id' => null, 'pi_token' => null,
            'wireguard_private_key' => null, 'ssh_host_private_key' => null, 'agent_secret' => null, 'error_code' => null, 'error_message' => null]);
    }
}
