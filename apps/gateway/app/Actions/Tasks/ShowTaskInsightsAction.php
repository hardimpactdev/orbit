<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\TaskInsights;
use App\Domain\Tasks\TaskInsightTotals;
use Carbon\CarbonImmutable;

final readonly class ShowTaskInsightsAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension, private TaskInsightTotals $totals) {}

    /**
     * The stored report, up to one refresh old; it never reads the task tables.
     *
     * @param  key-of<TaskInsights::Periods>  $period
     * @return array<string, mixed>
     */
    public function execute(string $period, ?int $projectId): array
    {
        $this->requireExtension->execute();

        return $this->totals->read($period, $projectId, CarbonImmutable::now());
    }
}
