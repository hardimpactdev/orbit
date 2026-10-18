<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Task;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/** A driver outage is group-only assistance that does not block observation and clears on recovery. */
final readonly class TaskObservationAvailability
{
    private const string Reason = 'Agent observation unavailable beyond the grace period.';

    public function clear(Task $group): void
    {
        DB::transaction(static function () use ($group): void {
            $current = Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ($current->assistance_kind === AssistanceKind::Observation) {
                TaskAssistance::apply($current);
            }
            if ($current->agent_unavailable_since !== null) {
                $current->update(['agent_unavailable_since' => null, 'agent_unavailable_notified_at' => null]);
            }
            $group->refresh();
        });
    }

    public function unavailableDecision(Task $group): TaskSessionDecision
    {
        return DB::transaction(static function () use ($group): TaskSessionDecision {
            $current = Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ($current->agent_unavailable_since === null) {
                $current->update(['agent_unavailable_since' => now()]);
            }
            $grace = max(0, Config::integer('orbit.tasks.observation_grace_seconds', 120));
            if ($current->agent_unavailable_since?->lte(now()->subSeconds($grace)) && $current->agent_unavailable_notified_at === null) {
                $current->update(['agent_unavailable_notified_at' => now()]);
                TaskAssistance::apply($current, AssistanceKind::Observation, reason: self::Reason);
                $group->refresh();

                return TaskSessionDecision::escalate(self::Reason);
            }
            $group->refresh();

            return new TaskSessionDecision(TaskSessionNextAction::Noop, 1.0, 'Waiting for an available agent observation.');
        });
    }
}
