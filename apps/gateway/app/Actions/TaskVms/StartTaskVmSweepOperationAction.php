<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\TaskVms\TaskVmCapabilities;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class StartTaskVmSweepOperationAction
{
    public function __construct(private StartOperationAction $operations) {}

    public function execute(int $nodeId, string $provider): Operation
    {
        TaskVmCapabilities::require('children');
        TaskVmCapabilities::require('receipts');
        $existing = Operation::query()->where('kind', 'task_vm.sweep_host')->where('target_type', 'task_vm_sweep')->where('target_id', $nodeId)->whereNotIn('status', ['succeeded', 'failed', 'cancelled'])->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($nodeId, $provider): Operation {
            $operation = $this->operations->execute('task_vm.sweep_host', 'task_vm_sweep', $nodeId, $nodeId,
                ['node_id' => $nodeId, 'provider' => $provider, 'deadline_at' => now()->addMinutes(30)->getTimestamp()], 'task-vm', null);
            $input = $operation->input;
            $input['sweep_id'] = $operation->id;
            $operation->update(['input' => $input]);

            return $operation;
        });
    }
}
