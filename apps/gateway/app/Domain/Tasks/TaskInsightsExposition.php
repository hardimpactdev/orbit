<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Project;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The stored [task insights](/reference/tasks#task-insights) totals in the Prometheus text format, for the Metrics role's
 * Prometheus. It reads `task_insight_totals` and the Project codes only, never tasks or records, so a scrape costs one small
 * query whatever the task count. Each gauge takes the current period's summary of one scope and period.
 */
final readonly class TaskInsightsExposition
{
    /** Summary field => metric suffix, help text, and the factor that turns the stored value into the metric's unit. */
    private const array Gauges = [
        'tasks' => ['tasks', 'Ended task groups in the period.', 1],
        'merged' => ['merged', 'Merged task groups in the period.', 1],
        'excluded' => ['excluded', 'Merged task groups that lack a time the lead time needs.', 1],
        'tokens' => ['tokens', 'Tokens of every agent thread of the ended task groups.', 1],
        'cost_usd' => ['cost_usd', 'Cost in USD of every agent thread of the ended task groups.', 1],
        'tokens_per_merged_pr' => ['tokens_per_merged_pr', 'Tokens divided by merged task groups.', 1],
        'cost_per_merged_pr' => ['cost_per_merged_pr_usd', 'Cost in USD divided by merged task groups.', 1],
        'yield' => ['yield_ratio', 'Merged task groups divided by ended task groups.', 1],
        'pr_first_pass' => ['pr_first_pass_ratio', 'Merged task groups with no fixup subtask, divided by merged ones.', 1],
        'subtask_first_pass' => ['subtask_first_pass_ratio', 'Reviewed subtasks whose first verdict was approved.', 1],
        'handoff_first_try' => ['handoff_first_try_ratio', 'Subtasks whose first finished handoff check passed.', 1],
        'wasted_share' => ['wasted_ratio', 'Tokens of unmerged task groups and replaced implementer threads, divided by tokens.', 1],
        'cache_hit' => ['cache_hit_ratio', 'Cached input tokens divided by all input tokens.', 1],
        'review_rounds' => ['review_rounds', 'Mean review verdicts per reviewed subtask.', 1],
        'questions_per_task' => ['questions_per_task', 'Mean questions per ended task group.', 1],
        'lead_time_ms' => ['lead_time_median_seconds', 'Median ready to merge time of merged task groups.', 0.001],
        'queue_ms' => ['queue_median_seconds', 'Median ready to start time.', 0.001],
        'work_ms' => ['work_median_seconds', 'Median start to settle time.', 0.001],
        'merge_wait_ms' => ['merge_wait_median_seconds', 'Median settle to merge time.', 0.001],
        'pr_merge_wait_ms' => ['pr_merge_wait_median_seconds', 'Median pull request open to merge time.', 0.001],
        'delivery_time_ms' => ['delivery_time_median_seconds', 'Median ready to production deploy time of merged task groups.', 0.001],
        'deploy_wait_ms' => ['deploy_wait_median_seconds', 'Median merge to production deploy time.', 0.001],
    ];

    public const string Prefix = 'orbit_task_insights_';

    public function render(): string
    {
        $totals = DB::table('task_insight_totals')->select(['project_id', 'period', 'computed_at', 'payload'])
            ->orderBy('scope')->orderBy('period')->get();
        $codes = Project::query()->whereIn('id', $totals->pluck('project_id')->filter()->unique()->values())->pluck('code', 'id');
        $samples = [];
        foreach ($totals as $total) {
            $projectId = $total->project_id === null ? null : ValidatedData::integer($total->project_id);
            if ($projectId !== null && ! $codes->has($projectId)) {
                continue;
            }
            $labels = '{project="'.self::label($projectId === null ? 'all' : ValidatedData::string($codes->get($projectId))).'",period="'.self::label(ValidatedData::string($total->period)).'"}';
            $samples['computed_timestamp_seconds'][] = $labels.' '.CarbonImmutable::parse(ValidatedData::string($total->computed_at), 'UTC')->getTimestamp();
            $current = ValidatedData::object(ValidatedData::object(json_decode(ValidatedData::string($total->payload), true, flags: JSON_THROW_ON_ERROR))['current'] ?? []);
            foreach (self::Gauges as $field => [$suffix, , $factor]) {
                $value = $current[$field] ?? null;
                if (is_int($value) || is_float($value)) {
                    $samples[$suffix][] = $labels.' '.self::number($value * $factor);
                }
            }
        }

        $help = ['computed_timestamp_seconds' => 'When the stored totals were computed, as a Unix time.'];
        foreach (self::Gauges as [$suffix, $text]) {
            $help[$suffix] = $text;
        }
        $text = '';
        foreach ($help as $suffix => $line) {
            if (! isset($samples[$suffix])) {
                continue;
            }
            $name = self::Prefix.$suffix;
            $text .= "# HELP {$name} {$line}\n# TYPE {$name} gauge\n";
            foreach ($samples[$suffix] as $sample) {
                $text .= $name.$sample."\n";
            }
        }

        return $text;
    }

    private static function number(int|float $value): string
    {
        return is_float($value) && floor($value) !== $value ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) (int) $value;
    }

    private static function label(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
