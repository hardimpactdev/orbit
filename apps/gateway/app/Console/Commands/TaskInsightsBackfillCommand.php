<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskInsightRecorder;
use App\Models\Task;
use Illuminate\Console\Command;

/**
 * Records every ended task group for [task insights](/reference/tasks#task-insights), in chunks. Running it again rewrites the same
 * records. The refresh tick records the groups of the trend by itself; this command covers the older ones.
 */
final class TaskInsightsBackfillCommand extends Command
{
    #[\Override]
    protected $signature = 'task:insights-backfill';

    #[\Override]
    protected $description = 'Record the task insights row of every ended task group.';

    public function handle(TaskInsightRecorder $recorder): int
    {
        $count = $recorder->recordAll(Task::topLevel()
            ->whereIn('status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses())));
        $this->line(json_encode(['recorded' => $count], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
