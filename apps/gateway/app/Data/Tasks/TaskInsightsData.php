<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** Task delivery numbers for one period and the period before it ([Task insights](/reference/tasks#task-insights)). */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInsightsData extends Data
{
    /**
     * @param  '7d'|'30d'  $period
     * @param  list<TaskInsightsWeekData>  $weekly  The last 12 weeks, oldest first, whatever the period.
     * @param  list<TaskInsightsDayData>  $daily  Each day of the period, oldest first.
     */
    public function __construct(
        public string $period,
        public string $from,
        public string $to,
        public TaskInsightsSummaryData $current,
        public TaskInsightsSummaryData $previous,
        public array $weekly,
        public array $daily,
        public TaskInsightsBreakdownsData $breakdowns,
    ) {}
}
