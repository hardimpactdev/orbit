<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\TaskVms\TaskVmSeedRefreshIntent;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/**
 * Starts the refresh of one task VM host's topology seed as one Operation. The host holds the lock, and the lock is the
 * one an image build takes, so a refresh and an image build never run together on a host. A refresh that is open is
 * returned instead of a second one. The intent reads GitHub and writes the vault, so it is captured before the
 * transaction opens; the transaction only checks again and records the Operation.
 */
final readonly class StartTaskVmSeedRefreshAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSeedRefreshIntent $intent) {}

    public function execute(Node $host): Operation
    {
        if (($open = $this->open($host)) instanceof Operation) {
            return $open;
        }
        $input = $this->intent->capture($host);

        return DB::transaction(function () use ($host, $input): Operation {
            $host = Node::query()->lockForUpdate()->findOrFail($host->id);

            return $this->open($host) ?? $this->start->execute('task_vm.seed_refresh', 'task_vm_host', $host->id, $host->id, $input, 'task-vm', null,
                lockKey: OperationStore::lockKey('task_vm_host', $host->id));
        });
    }

    private function open(Node $host): ?Operation
    {
        return Operation::query()->where('kind', 'task_vm.seed_refresh')->where('target_type', 'task_vm_host')->where('target_id', $host->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->first();
    }
}
