<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\OperationSweeper;
use Illuminate\Console\Command;

/** Queues the job again for operations that a lost worker or a lost job left behind. */
final class SweepOperationsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:operations-sweep';

    #[\Override]
    protected $description = 'Queue the job again for operations whose worker or queued job was lost.';

    public function handle(OperationSweeper $sweeper): int
    {
        $count = $sweeper->sweep();

        if ($count > 0) {
            $this->info("Queued {$count} operation(s) again.");
        }

        return self::SUCCESS;
    }
}
