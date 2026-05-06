<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleLock;

final readonly class SchedulesFixer
{
    public function __construct(
        private RemoteShell $remoteShell,
        private OrbitSchedulerProgramRenderer $renderer = new OrbitSchedulerProgramRenderer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(Schedule $schedule, DriftEntry $entry): ?array
    {
        $node = $this->targetNode($schedule);

        if (! $node instanceof Node) {
            return null;
        }

        if ($entry->key === 'schedule.lock_stuck') {
            ScheduleLock::query()
                ->where('node_id', $node->id)
                ->where('schedule_key', $schedule->schedule_key)
                ->delete();

            return $this->action($schedule, $node, $entry);
        }

        $script = match ($entry->key) {
            'schedule.scheduler_missing' => $this->renderer->installScript($node),
            'schedule.scheduler_stopped' => "sudo supervisorctl start 'orbit_scheduler'",
            default => null,
        };

        if ($script === null) {
            return null;
        }

        $this->remoteShell->run($node, $script, ['throw' => true]);

        return $this->action($schedule, $node, $entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function action(Schedule $schedule, Node $node, DriftEntry $entry): array
    {
        return [
            'family' => 'schedule',
            'node' => $node->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Repaired Orbit Scheduler for schedule {$schedule->name}.",
            'details' => [
                'schedule' => $schedule->name,
            ],
        ];
    }

    private function targetNode(Schedule $schedule): ?Node
    {
        $schedule->loadMissing(['app.node', 'node']);

        if ($schedule->scope === 'app') {
            return $schedule->app?->node;
        }

        if ($schedule->scope === 'node') {
            return $schedule->node;
        }

        if ($schedule->scope === 'orbit') {
            $node = Node::query()
                ->where('role', 'gateway')
                ->where('status', 'active')
                ->first();

            return $node instanceof Node ? $node : null;
        }

        return null;
    }
}
