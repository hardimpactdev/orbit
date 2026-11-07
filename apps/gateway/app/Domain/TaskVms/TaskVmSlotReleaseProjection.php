<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Jobs\TaskVms\RefillTaskVmSlots;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the release of a slot (docs/cli/tasks/vm-slot-release.mmd), as its own run or as the last step of a cancel. An identity whose
 * task VM was deleted is free again: it lets go of the group and of the claim's Pi token and model key, and its workspace Instance is
 * unbound, not deleted. Its Node, peer and keys stay. One whose VM was not deleted is `failed`: the slot rule releases it again while it still
 * holds a model key that was not revoked, and enrols it again once it holds none.
 */
final readonly class TaskVmSlotReleaseProjection implements DirectOperationProjection
{
    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);

        // A release without a model key to revoke takes no secret; the submission sends the empty map as an object.
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
            $revoked = $passed || NativeRunResult::stepStatus(ValidatedData::object($data['result'] ?? []), 'Revoke the model key') === 'succeeded';
            $this->record($slot, $passed, $passed ? null : (ValidatedData::nullableString($data['error_code'] ?? null) ?? 'task_vm.slot_release_failed'), $revoked);
            $result = ['slot' => $slot->name, 'released' => $passed];

            return app(DirectOperation::class)->project($operation, [...$data, 'result' => $result], $result);
        });
    }

    /** The identity after its release: free, or failed, still holding its model key unless the release revoked it. */
    public function record(TaskVm $slot, bool $passed, ?string $errorCode, bool $revoked): void
    {
        Instance::query()->where('node_id', $slot->node_id)->whereNotNull('task_workspace_owner_id')
            ->update(['task_workspace_owner_id' => null, 'branch_override' => null, 'status' => InstanceState::Reserved]);
        $slot->update($passed
            ? ['state' => TaskVmState::Parked, 'group_id' => null, 'claimed_at' => null, 'pi_token' => null, 'model_key' => null, 'ai_router_origin' => null,
                'stop_confirmed_at' => now(), 'error_code' => null, 'error_message' => null]
            : ['state' => TaskVmState::Failed, 'group_id' => null, 'error_code' => $errorCode, 'error_message' => 'The slot release did not pass.',
                ...$revoked ? ['model_key' => null, 'ai_router_origin' => null] : []]);
        $host = (int) $slot->host_node_id;
        DB::afterCommit(static fn () => RefillTaskVmSlots::dispatchFor($host));
    }
}
