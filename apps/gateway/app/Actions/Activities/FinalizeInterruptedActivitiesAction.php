<?php

declare(strict_types=1);

namespace App\Actions\Activities;

use App\Domain\Activity\ActivityBroadcaster;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Records Activity outcomes selected by native maintenance or immediate request shutdown. */
final readonly class FinalizeInterruptedActivitiesAction
{
    public const string ERROR_CODE = 'activity.interrupted';

    /**
     * Marks the given rows interrupted when they are still running, then broadcasts one
     * `activity.updated` notice for each row this call ended. The write is a query-builder update,
     * which fires no model events, so the notice is explicit. It is sent after the outcome is saved.
     * A broadcast failure is logged and leaves that outcome in place.
     *
     * @param  Builder<Activity>  $query
     */
    public static function finalize(Builder $query, Carbon $now): int
    {
        $ids = (clone $query)->where('status', 'running')->orderBy('id')->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $updated = (clone $query)->where('status', 'running')->whereKey($ids->all())->update([
            'status' => 'failed',
            'error_code' => self::ERROR_CODE,
            'updated_at' => $now,
        ]);

        if ($updated === 0) {
            return 0;
        }

        // The notice never carries `properties`, and a swept row's properties can be large.
        $ended = Activity::query()
            ->whereKey($ids->all())
            ->where('status', 'failed')
            ->where('error_code', self::ERROR_CODE)
            ->orderBy('id')
            ->get([
                'id',
                'request_id',
                'command',
                'status',
                'caller_node_id',
                'target_node_id',
                'error_code',
                'duration_ms',
                'created_at',
            ]);
        $broadcaster = app(ActivityBroadcaster::class);

        foreach ($ended as $activity) {
            $broadcaster->updated($activity);
        }

        return $updated;
    }
}
