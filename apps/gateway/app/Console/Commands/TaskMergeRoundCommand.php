<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\RunTaskMergeRoundAction;
use App\Domain\Tasks\TaskExtensionState;
use Illuminate\Console\Command;

/**
 * One tick of the merge round of task copies whose publish conflicted (docs/reference/tasks.md#the-merge-round), which the Gateway
 * scheduler runs every minute. It prints one JSON object with the result for each group whose newest publish conflicted.
 */
final class TaskMergeRoundCommand extends Command
{
    #[\Override]
    protected $signature = 'task:merge-round';

    #[\Override]
    protected $description = 'Start the next merge round step of each task copy group whose publish conflicted.';

    public function handle(RunTaskMergeRoundAction $tick, TaskExtensionState $extension): int
    {
        if (! $extension->enabled()) {
            return self::SUCCESS;
        }

        $this->line(json_encode(['groups' => $tick->execute()], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
