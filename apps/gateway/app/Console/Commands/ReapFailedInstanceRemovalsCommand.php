<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Instances\InstanceRemovalReaper;
use Illuminate\Console\Command;

/** Starts each failed destroy that its backoff allows again (docs/reference/instance-removal.md#retry-and-ownership). */
final class ReapFailedInstanceRemovalsCommand extends Command
{
    #[\Override]
    protected $signature = InstanceRemovalReaper::Trigger;

    #[\Override]
    protected $description = 'Start failed Instance removals again with backoff, and report a removal stuck after three failed retries.';

    public function handle(InstanceRemovalReaper $reaper): int
    {
        $started = $reaper->run();
        $this->line('Started '.count($started).' removal retries.');

        return self::SUCCESS;
    }
}
