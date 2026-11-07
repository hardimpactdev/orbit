<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Tasks\RunTaskPullRequestGateAction;
use App\Domain\Tasks\TaskExtensionState;
use Illuminate\Console\Command;

/**
 * One tick of the gate check of task pull requests, which the Gateway scheduler runs every minute.
 * It prints one JSON object with the result for each group it looked at.
 */
final class TaskPullRequestGateCommand extends Command
{
    #[\Override]
    protected $signature = 'task:pr-gate';

    #[\Override]
    protected $description = 'Admit the ready step of a green draft pull request and the merge step of an approved one.';

    public function handle(RunTaskPullRequestGateAction $gate, TaskExtensionState $extension): int
    {
        if (! $extension->enabled()) {
            return self::SUCCESS;
        }

        $this->line(json_encode(['groups' => $gate->execute()], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
