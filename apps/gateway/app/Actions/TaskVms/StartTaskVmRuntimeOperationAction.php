<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/** Records runtime intent for the VM's one native DAG. No child admission or runtime work. */
final readonly class StartTaskVmRuntimeOperationAction
{
    public function __construct(private StartOperationAction $start, private TaskVmEnrollmentIntent $intent) {}

    public function execute(int $taskVmId): ?Operation
    {
        return $this->intent->admit(fn (): ?Operation => DB::transaction(function () use ($taskVmId): ?Operation {
            $vm = TaskVm::query()->find($taskVmId);
            if ($vm?->state !== TaskVmState::Provisioning) {
                return null;
            }
            $existing = Operation::query()->where('kind', 'task_vm.prepare_runtime')->where('target_type', 'task_vm')
                ->where('target_id', $vm->id)->whereNotIn('status', ['failed', 'cancelled'])->latest('id')->first();
            if ($existing !== null) {
                return $existing;
            }
            $input = $this->intent->capture($vm);
            $nodeId = $vm->node_id ?? throw new \LogicException('Enrollment did not reserve its Node.');
            $input = [...$input, ...$this->intent->privateInput($vm, $nodeId)];

            return $this->start->execute('task_vm.prepare_runtime', 'task_vm', $vm->id, $nodeId, $input, 'task-vm', null);
        }));
    }
}
