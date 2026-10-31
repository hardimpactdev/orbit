<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Records one native enrollment root on the admitted first-visit host. */
final readonly class StartTaskVmEnrollmentOperationAction
{
    public function __construct(private StartOperationAction $start, private TaskVmEnrollmentIntent $intent) {}

    public function execute(int $taskVmId): ?Operation
    {
        return $this->intent->admit(fn (): ?Operation => DB::transaction(function () use ($taskVmId): ?Operation {
            $vm = TaskVm::query()->find($taskVmId);
            if ($vm?->state !== TaskVmState::Provisioning) {
                return null;
            }
            $existing = Operation::query()->where('kind', 'task_vm.enroll')->where('target_type', 'task_vm')
                ->where('target_id', $vm->id)->whereNotIn('status', ['failed', 'cancelled'])->latest('id')->first();
            if ($existing !== null) {
                return $existing;
            }
            $input = $this->intent->capture($vm);
            $nodeId = ValidatedData::integer($input['execution_node_id']);
            $input = [...$input, ...$this->intent->privateInput($vm, $nodeId)];

            return $this->start->execute('task_vm.enroll', 'task_vm', $vm->id, $nodeId, $input, 'task-vm', null);
        }));
    }
}
