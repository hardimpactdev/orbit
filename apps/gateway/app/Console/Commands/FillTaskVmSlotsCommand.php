<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSlotPool;
use App\Support\ValidatedData;
use Illuminate\Console\Command;

/**
 * Runs the refill rule of one task VM host's ready slots now. The Gateway runs the same rule after every enrol, claim,
 * destroy, image promotion and settings change; an operator runs it after a failed enrol, or to start a new pool.
 */
final class FillTaskVmSlotsCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:fill-slots {host : Task VM host Node ID or name}';

    #[\Override]
    protected $description = 'Enrol one ready slot, or replace one stale slot, on a task VM host.';

    public function handle(TaskVmSlotPool $pool): int
    {
        try {
            $operation = $pool->refill($this->activeNode(ValidatedData::string($this->argument('host')))->id);
        } catch (ResourceOperationException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->line($operation === null ? 'Nothing to do.' : 'Operation '.$operation->id.' '.$operation->kind.' '.$operation->status->value);

        return self::SUCCESS;
    }
}
