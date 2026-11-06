<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\TaskVms\StartTaskVmImageRefreshAction;
use App\Domain\Shared\ResourceOperationException;
use App\Support\ValidatedData;
use Illuminate\Console\Command;

/**
 * Starts the refresh of one task VM host's image and seed. There is no CLI command of its own; an operator runs it for one
 * host, or `task-vms:refresh-images` for every host.
 */
final class RefreshTaskVmImageCommand extends Command
{
    use ResolvesTaskVmHostNode;

    #[\Override]
    protected $signature = 'task-vms:refresh-image {host : Task VM host Node ID or name} {--seed=build : build (the nested fleet becomes the seed) or keep (the image part alone)}';

    #[\Override]
    protected $description = 'Refresh the task image and the topology seed of one task VM host to the release the Gateway runs.';

    public function handle(StartTaskVmImageRefreshAction $refresh): int
    {
        try {
            $operation = $refresh->execute($this->activeNode($this->argument('host')), 'operator', (string) $this->option('seed'));
        } catch (ResourceOperationException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }
        $this->line('Operation '.$operation->id.' '.$operation->status->value);
        $asked = (string) $this->option('seed');
        $running = ValidatedData::string(data_get($operation->input, 'refresh.seed', 'build'));
        if ($running !== $asked) {
            $this->warn('A refresh of this host is already open with seed='.$running.'; it is returned instead of seed='.$asked.'.');
        }

        return self::SUCCESS;
    }
}
