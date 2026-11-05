<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\StartTaskVmReadyCopiesAction;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

/**
 * Starts the save of the ready copies of one running task VM. There is no CLI command of its own: the seed refresh
 * of stage 6 runs the same steps in its refresh VM, and an operator can run them for one VM.
 */
final class SaveTaskVmReadyCopiesCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:save-ready-copies {vm : Numeric TaskVM ID}';

    #[\Override]
    protected $description = 'Save the nested fleet of a running task VM as self-contained ready copies in its seed disk.';

    public function handle(StartTaskVmReadyCopiesAction $save): int
    {
        $value = $this->argument('vm');
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            $this->error('A positive TaskVM ID is required.');

            return self::FAILURE;
        }
        try {
            $operation = $save->execute((int) $value);
        } catch (ResourceOperationException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->line('Operation '.$operation->id.' '.$operation->status->value);

        return self::SUCCESS;
    }
}
