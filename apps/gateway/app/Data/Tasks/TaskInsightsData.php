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
     * @param  '24h'|'7d'|'30d'  $period
     * @param  list<TaskInsightsWeekData>  $weekly  The last 12 weeks, oldest first, whatever the period.
     * @param  list<TaskInsightsDayData>  $daily  Each day of a 7d or 30d period, oldest first; empty for 24h.
     * @param  list<TaskInsightsHourData>  $hourly  Each hour of a 24h period, oldest first; empty for 7d and 30d.
     */
    public function __construct(
        public string $period,
        public string $from,
        public string $to,
        public TaskInsightsSummaryData $current,
        public TaskInsightsSummaryData $previous,
        public array $weekly,
        public array $daily,
        public array $hourly,
        public TaskInsightsBreakdownsData $breakdowns,
    ) {}
}
