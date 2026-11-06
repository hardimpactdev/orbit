<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\FailTaskVmAction;
use App\Domain\Operations\DirectOperationProjection;
use App\Jobs\TaskVms\RefillTaskVmSlots;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the end of one slot enrol (docs/cli/tasks/vm-slots.mmd): the provision records the enrolled VM as for any task
 * VM, then the stopped VM becomes a ready slot (`parked`, no group). A failed, interrupted or cancelled enrol leaves the row
 * `failed`; the refill rule that runs after it destroys the VM.
 */
final readonly class TaskVmSlotEnrolProjection implements DirectOperationProjection
{
    public function __construct(private TaskVmProvisionIntent $intent, private TaskVmProvisionProjection $provision, private FailTaskVmAction $fail) {}

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $input = $this->intent->input($operation);
        $intent = ValidatedData::object($input['intent']);
        // The VM provider puts the whole intent into one argument, so the converge intent travels as an input of its own.
        $converge = ValidatedData::object($intent['converge'] ?? []);
        unset($intent['converge']);

        return [...$input, 'intent' => $intent, 'converge' => $converge];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $projected = $this->provision->apply($operation, $data);
            $vm = TaskVm::query()->findOrFail($operation->target_id);
            if (($data['status'] ?? null) === 'succeeded') {
                TaskVm::query()->whereKey($vm->id)->whereNull('group_id')->where('state', TaskVmState::Ready)
                    ->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => now()]);
            } elseif (in_array($data['status'] ?? null, ['interrupted', 'cancelled'], true)) {
                // The provision projection fails only a failed run; an enrol that ended otherwise must not hold the pool.
                $this->fail->execute($vm, [TaskVmState::Provisioning], ValidatedData::nullableString($data['error_code'] ?? null) ?? 'task_vm.slot_enrol_'.$data['status'],
                    'The slot enrol did not finish.', 'The slot enrol did not finish.');
            }
            RefillTaskVmSlots::dispatchFor($vm->host_node_id);

            return $projected;
        });
    }
}
