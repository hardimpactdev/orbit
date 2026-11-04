<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmSlotEnrollIntent;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * Records one slot of a task VM host and starts its enrolment as one Operation. A slot that is enrolling or failed
 * enrols again and keeps its keys; an enrolled slot is refused. No host or provider call: Swamp does the work.
 */
final readonly class StartTaskVmSlotEnrollAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSlotEnrollIntent $intent, private TaskVmEnrollmentIntent $enrollment) {}

    public function execute(Node $host, int $slot): Operation
    {
        return $this->enrollment->admit(fn (): Operation => DB::transaction(function () use ($host, $slot): Operation {
            $host = Node::query()->lockForUpdate()->findOrFail($host->id);
            $vm = $this->intent->reserve($host, $slot);
            $existing = Operation::query()->where('kind', 'task_vm.slot_enroll')->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->first();
            if ($existing instanceof Operation) {
                return $existing;
            }

            return $this->start->execute('task_vm.slot_enroll', 'task_vm', $vm->id, $host->id, $this->intent->capture($vm), 'task-vm', null,
                lockKey: OperationStore::lockKey('task_vm', $vm->id));
        }));
    }
}
