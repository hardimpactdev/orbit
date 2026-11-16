<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\TaskCopySettle;
use App\Domain\Tasks\TaskExtensionState;
use Illuminate\Console\Command;

/**
 * One tick of the settle on merge of task copy groups, which the Gateway scheduler runs every five minutes.
 * It prints one JSON object with the result for each group it looked at.
 */
final class TaskCopySettleCommand extends Command
{
    #[\Override]
    protected $signature = 'task:copy-settle';

    #[\Override]
    protected $description = 'Complete each task copy group whose watched pull request merged, and admit its copy\'s destroy.';

    public function handle(TaskCopySettle $settle, TaskExtensionState $extension): int
    {
        if (! $extension->enabled()) {
            return self::SUCCESS;
        }

        $this->line(json_encode(['groups' => $settle->execute()], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
