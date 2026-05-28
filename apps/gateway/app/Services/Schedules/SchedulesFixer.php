<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\ScheduleRun;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class SchedulesFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private NodeRoleAssignments $nodeRoleAssignments = new NodeRoleAssignments,
        private OrbitSchedulerProgramRenderer $renderer = new OrbitSchedulerProgramRenderer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(Schedule $schedule, DriftEntry $entry): ?array
    {
        $gatewayNode = $this->gatewayNode();

        return $gatewayNode instanceof Node
            ? $this->fixGateway($gatewayNode, $entry, $schedule)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fixGateway(Node $gatewayNode, DriftEntry $entry, ?Schedule $schedule = null): ?array
    {
        if ($entry->key === 'schedule.lock_stuck') {
            $this->releaseStuckLock($gatewayNode, $entry, $schedule);

            return $this->action($gatewayNode, $entry, $schedule);
        }

        $script = match ($entry->key) {
            'schedule.scheduler_missing' => $this->renderer->installScript($gatewayNode),
            'schedule.scheduler_stopped' => $this->renderer->installScript($gatewayNode),
            default => null,
        };

        if ($script === null) {
            return null;
        }

        $this->remoteShell->run($gatewayNode, $script, ['throw' => true]);

        return $this->action($gatewayNode, $entry, $schedule);
    }

    private function releaseStuckLock(Node $gatewayNode, DriftEntry $entry, ?Schedule $schedule): void
    {
        $scheduleKey = is_string($entry->detail['schedule_key'] ?? null)
            ? $entry->detail['schedule_key']
            : $schedule?->schedule_key;

        $query = ScheduleLock::query()->where('node_id', $gatewayNode->id);

        if ($scheduleKey !== null) {
            $query->where('schedule_key', $scheduleKey);
        }

        $query->delete();

        if ($scheduleKey === null) {
            return;
        }

        $runningRun = ScheduleRun::query()
            ->where('schedule_key', $scheduleKey)
            ->where('status', 'running')
            ->latest('started_at')
            ->first();

        if (! $runningRun instanceof ScheduleRun) {
            return;
        }

        $runningRun->forceFill([
            'status' => 'failed',
            'exit_code' => $runningRun->exit_code ?? 1,
            'stderr' => trim((string) $runningRun->stderr."\nSchedule lock was released by doctor restore."),
            'finished_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function action(Node $gatewayNode, DriftEntry $entry, ?Schedule $schedule): array
    {
        return [
            'family' => 'schedule',
            'node' => $gatewayNode->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => $schedule instanceof Schedule
                ? "Repaired Orbit Scheduler for schedule {$schedule->name}."
                : 'Repaired gateway Orbit Scheduler.',
            'details' => array_filter([
                'schedule' => $schedule?->name,
                ...($entry->detail ?? []),
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    private function gatewayNode(): ?Node
    {
        return $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->first();
    }
}
