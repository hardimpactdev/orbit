<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskInsightTotals;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * One tick of the [task insights](/reference/tasks#task-insights) totals, which the Gateway scheduler runs every five minutes.
 * It records the ended groups whose source rows changed since their record, then recomputes every stored report from the records.
 */
final class TaskInsightsRefreshCommand extends Command
{
    #[\Override]
    protected $signature = 'task:insights-refresh';

    #[\Override]
    protected $description = 'Record changed task groups and recompute the stored task insights reports.';

    public function handle(TaskInsightTotals $totals, TaskExtensionState $extension): int
    {
        if (! $extension->enabled()) {
            return self::SUCCESS;
        }

        $totals->refresh(CarbonImmutable::now());

        return self::SUCCESS;
    }
}
