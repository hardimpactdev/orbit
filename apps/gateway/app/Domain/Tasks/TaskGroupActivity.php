<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * When a group last did something, read from its Operations (docs/reference/tasks.md#last-activity): the newest time one was
 * created, started or ended.
 */
final class TaskGroupActivity
{
    /**
     * @param  list<int>  $groups
     * @return array<int, string|null> group id => its last activity, for every group asked
     */
    public static function of(array $groups): array
    {
        if ($groups === []) {
            return [];
        }
        $latest = [];
        foreach (Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->whereIn('target_id', $groups)->groupBy('target_id')
            ->selectRaw('target_id, max(created_at) as created, max(started_at) as started, max(finished_at) as finished')->toBase()->get() as $row) {
            $times = array_filter([$row->created ?? null, $row->started ?? null, $row->finished ?? null], is_string(...));
            if ($times !== []) {
                $latest[ValidatedData::integer($row->target_id ?? null)] = max(array_map(
                    static fn (string $time): string => CarbonImmutable::parse($time)->utc()->format(DateTimeInterface::ATOM), $times));
            }
        }
        $activity = [];
        foreach ($groups as $group) {
            $activity[$group] = $latest[$group] ?? null;
        }

        return $activity;
    }
}
