<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\StartTaskVmFleetBuildAction;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

/**
 * Starts the build of the nested fleet of one running task VM from stock Ubuntu. There is no CLI command of its own: the seed refresh of
 * stage 6 calls the same steps for a host whose seed has no usable ready copies, and an operator can run them for one VM.
 */
final class BuildTaskVmFleetCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:build-fleet {vm : Numeric TaskVM ID}';

    #[\Override]
    protected $description = 'Build the nested fleet of a running task VM from stock Ubuntu: a Gateway with its own Swamp, app-dev and app-prod.';

    public function handle(StartTaskVmFleetBuildAction $build): int
    {
        $value = $this->argument('vm');
        if (! ctype_digit($value) || (int) $value < 1) {
            $this->error('A positive TaskVM ID is required.');

            return self::FAILURE;
        }
        try {
            $operation = $build->execute((int) $value);
        } catch (ResourceOperationException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->line('Operation '.$operation->id.' '.$operation->status->value);

        return self::SUCCESS;
    }
}
