<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * When a group last did something, read from its Operations (docs/reference/tasks.md#task-places): the newest time one was
 * created, started or ended, and the newest one that has not ended.
 */
final class TaskGroupActivity
{
    /**
     * @param  list<int>  $groups
     * @return array<int, array{last_activity_at: string|null, active_operation_id: int|null}> group id => activity, for every group asked
     */
    public static function of(array $groups): array
    {
        if ($groups === []) {
            return [];
        }
        $operations = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->whereIn('target_id', $groups);
        $latest = [];
        foreach ((clone $operations)->groupBy('target_id')
            ->selectRaw('target_id, max(created_at) as created, max(started_at) as started, max(finished_at) as finished')->toBase()->get() as $row) {
            $times = array_filter([$row->created ?? null, $row->started ?? null, $row->finished ?? null], is_string(...));
            if ($times !== []) {
                $latest[ValidatedData::integer($row->target_id ?? null)] = max(array_map(
                    static fn (string $time): string => CarbonImmutable::parse($time)->utc()->format(DateTimeInterface::ATOM), $times));
            }
        }
        $open = $operations->whereNotIn('status', OperationStatus::terminalValues())->orderBy('id')->pluck('id', 'target_id')->all();
        $activity = [];
        foreach ($groups as $group) {
            $activity[$group] = ['last_activity_at' => $latest[$group] ?? null, 'active_operation_id' => isset($open[$group]) ? ValidatedData::integer($open[$group]) : null];
        }

        return $activity;
    }

    /** @return array{last_activity_at: string|null, active_operation_id: int|null} */
    public static function ofGroup(int $group): array
    {
        return self::of([$group])[$group];
    }
}
