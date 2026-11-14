<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\RunTaskPublishWhenDoneAction;
use App\Domain\Tasks\TaskExtensionState;
use Illuminate\Console\Command;

/**
 * One tick of the automatic publish of task copies in `pr_when_done`, which the Gateway scheduler runs every minute.
 * It prints one JSON object with the result for each group whose work is done.
 */
final class TaskPublishWhenDoneCommand extends Command
{
    #[\Override]
    protected $signature = 'task:pr-when-done';

    #[\Override]
    protected $description = 'Publish the done check of each task copy group in pr_when_done.';

    public function handle(RunTaskPublishWhenDoneAction $tick, TaskExtensionState $extension): int
    {
        if (! $extension->enabled()) {
            return self::SUCCESS;
        }

        $this->line(json_encode(['groups' => $tick->execute()], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
