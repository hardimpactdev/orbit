<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Reads the family's server-recorded member set without coercing IDs or changing its order. */
final class InstanceLifecycleInput
{
    /** The caller's transaction holds the row mutex, including on SQLite, before admission side effects. */
    public static function lock(int $id): Instance
    {
        $instance = Instance::query()->lockForUpdate()->findOrFail($id);
        DB::table('instances')->where('id', $id)->update(['updated_at' => $instance->getRawOriginal('updated_at')]);

        return $instance;
    }

    /** One admission rule for lifecycle roots and related runtime work, including removal retries.
     * @param  list<int>  $ids
     * @param  list<int>  $processes
     * @param  list<int>  $schedules
     */
    public static function assertIdle(array $ids, array $processes = [], array $schedules = [], string $errorCode = 'operation.target_busy'): void
    {
        if (Operation::query()->whereNotIn('status', OperationStatus::terminalValues())
            ->where(function ($query) use ($ids, $processes, $schedules): void {
                $query->where(fn ($row) => $row->where('target_type', 'instance')->whereIn('target_id', $ids))
                    ->orWhere(fn ($row) => $row->where('target_type', 'process')->whereIn('target_id', $processes))
                    ->orWhere(fn ($row) => $row->where('target_type', 'schedule')->whereIn('target_id', $schedules));
            })->exists()) {
            throw new ResourceOperationException($errorCode, 'Lifecycle, Process or Schedule work is still queued, running or waiting.', 409,
                details: ['reason' => 'busy']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    public static function members(array $input): array
    {
        $members = $input['members'] ?? null;
        if (! is_array($members) || ! array_is_list($members)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The lifecycle intent requires its recorded member set.', 422);
        }

        return array_map(ValidatedData::object(...), $members);
    }
}
