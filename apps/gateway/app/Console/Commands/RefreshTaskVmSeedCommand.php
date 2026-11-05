<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\StartTaskVmSeedRefreshAction;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

/**
 * Starts the refresh of one task VM host's topology seed. There is no CLI command of its own: the release flow starts it
 * for every host in parallel, and an operator can run it for one host.
 */
final class RefreshTaskVmSeedCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:refresh-seed {host : Task VM host Node ID or name}';

    #[\Override]
    protected $description = 'Refresh the topology seed of one task VM host with the ready nested topology of the running release.';

    public function handle(StartTaskVmSeedRefreshAction $refresh): int
    {
        try {
            $operation = $refresh->execute($this->activeNode($this->argument('host')));
        } catch (ResourceOperationException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->line('Operation '.$operation->id.' '.$operation->status->value);

        return self::SUCCESS;
    }
}
