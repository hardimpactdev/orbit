<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\DirectOperationProjection;
use App\Jobs\TaskVms\RefillTaskVmSlots;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the end of one slot enrol (docs/cli/tasks/vm-slots.mmd): the provision records the enrolled VM as for any task
 * VM, then the stopped VM becomes a ready slot (`parked`, no group). A failed enrol stays `failed`; the refill rule that
 * runs after it destroys the VM.
 */
final readonly class TaskVmSlotEnrolProjection implements DirectOperationProjection
{
    public function __construct(private TaskVmProvisionIntent $intent, private TaskVmProvisionProjection $provision) {}

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
            if (($data['status'] ?? null) === 'succeeded') {
                TaskVm::query()->whereKey($operation->target_id)->whereNull('group_id')->where('state', TaskVmState::Ready)
                    ->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => now()]);
            }
            RefillTaskVmSlots::dispatchFor(TaskVm::query()->find($operation->target_id)?->host_node_id);

            return $projected;
        });
    }
}
