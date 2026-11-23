<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Console;

use Illuminate\Console\Command;
use Orbit\Extensions\Conn\Domain\Push\ConnPushDevices;

/** Prunes Conn attention events older than 7 days. */
final class PruneConnAttentionEventsCommand extends Command
{
    protected $signature = 'conn:push:prune-events';

    protected $description = 'Prune Conn push attention events older than 7 days.';

    public function handle(ConnPushDevices $devices): int
    {
        $pruned = $devices->pruneEvents();
        $this->info("Pruned {$pruned} Conn attention events.");

        return self::SUCCESS;
    }
}
