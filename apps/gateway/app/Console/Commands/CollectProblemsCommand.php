<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Problems\ProblemCollection;
use App\Domain\Tasks\TaskExtensionState;
use Illuminate\Console\Command;

final class CollectProblemsCommand extends Command
{
    #[\Override]
    protected $signature = 'problems:collect';

    #[\Override]
    protected $description = 'Queue collection of recurring Doctor, Activity, log, and assistance problems.';

    public function handle(ProblemCollection $collection, TaskExtensionState $extension): int
    {
        if ($extension->enabled()) {
            $collection->dispatch();
        }

        return self::SUCCESS;
    }
}
