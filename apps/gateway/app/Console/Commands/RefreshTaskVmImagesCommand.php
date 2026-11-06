<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\TaskVms\TaskVmImageRefreshTrigger;
use Illuminate\Console\Command;

/** Admits the refresh of the task image of every Linux task VM host. */
final class RefreshTaskVmImagesCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:refresh-images';

    #[\Override]
    protected $description = 'Refresh the task image and the topology seed of every task VM host to the release the Gateway runs.';

    public function handle(TaskVmImageRefreshTrigger $trigger): int
    {
        foreach ($trigger->admitAll('operator') as $host => $result) {
            $this->line($host.': '.$result);
        }

        return self::SUCCESS;
    }
}
