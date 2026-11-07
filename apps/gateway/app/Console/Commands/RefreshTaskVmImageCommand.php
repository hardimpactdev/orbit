<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\StartTaskVmImageRefreshAction;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

/**
 * Starts the refresh of one task VM host's image and seed. There is no CLI command of its own: a release that changed
 * playbooks, pins or lock files and the daily schedule start it for every host, and an operator can run it for one.
 */
final class RefreshTaskVmImageCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:refresh-image {host : Task VM host Node ID or name} {--from-stock : Delete the base VM first and build it from the stock image}';

    #[\Override]
    protected $description = 'Refresh the task image of one task VM host to the release the Gateway runs.';

    public function handle(StartTaskVmImageRefreshAction $refresh): int
    {
        try {
            $operation = $refresh->execute($this->activeNode($this->argument('host')), 'operator', fromStock: (bool) $this->option('from-stock'));
        } catch (ResourceOperationException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->line('Operation '.$operation->id.' '.$operation->status->value);

        return self::SUCCESS;
    }
}
