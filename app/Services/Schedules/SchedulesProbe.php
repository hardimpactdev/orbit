<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\SchedulerState;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use Carbon\CarbonInterface;

final readonly class SchedulesProbe
{
    private const int FreshnessMinutes = 10;

    public function __construct(
        private RuntimeBackendProbe $runtimeBackendProbe,
    ) {}

    public function key(): string
    {
        return 'schedule';
    }

    public function label(): string
    {
        return 'Schedules';
    }

    public function introspect(Schedule $schedule): ProbeSnapshot
    {
        $node = $this->targetNode($schedule);

        if (! $node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        $runtime = $this->runtimeBackendProbe->check($node);
        $schedulerState = SchedulerState::query()->where('node_id', $node->id)->first();

        $schedulerStatus = null;

        if ($runtime->available) {
            $result = $this->runtimeBackendProbe->remoteShell()->run(
                $node,
                'supervisorctl status orbit_scheduler 2>/dev/null | awk \'{print $2}\' || true',
                ['timeout' => 15, 'throw' => false],
            );
            $schedulerStatus = $this->normalizeSchedulerStatus(trim($result->stdout));
        }

        return new ProbeSnapshot([
            $schedule->schedule_key => [
                'runtime_available' => $runtime->available,
                'scheduler_status' => $schedulerStatus,
                'heartbeat_at' => $schedulerState?->heartbeat_at?->toISOString(),
                'registry_synced_at' => $schedulerState?->registry_synced_at?->toISOString(),
            ],
        ]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diff(Schedule $schedule, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->checkRecordCompleteness($schedule),
            ...$this->checkTargetEligibility($schedule),
            ...$this->checkRuntimeAndScheduler($schedule, $snapshot),
            ...$this->checkFreshness($schedule, $snapshot),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Schedule $schedule): array
    {
        $validScope = in_array($schedule->scope, ['app', 'node', 'orbit'], true);
        $validExecution = in_array($schedule->execution_type, ['command', 'artisan'], true);

        if (
            $schedule->schedule_key === ''
            || $schedule->name === ''
            || ! $validScope
            || $schedule->target_name === ''
            || $schedule->interval === ''
            || $schedule->timezone === ''
            || ! $validExecution
            || $schedule->execution_value === ''
        ) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'schedule.record_incomplete',
                    kind: DriftKind::Missing,
                    summary: "Schedule {$schedule->name} is missing required intent fields.",
                    detail: [
                        'schedule' => $schedule->name,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkTargetEligibility(Schedule $schedule): array
    {
        $node = $this->targetNode($schedule);

        if (! $node instanceof Node) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'schedule.target_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Schedule {$schedule->name} does not resolve to a valid target node.",
                    detail: [
                        'schedule' => $schedule->name,
                        'scope' => $schedule->scope,
                        'target' => $schedule->target_name,
                    ],
                ),
            ];
        }

        if ($node->status !== 'active' || ! in_array($node->role, ['gateway', 'app'], true)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'schedule.target_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Schedule {$schedule->name} targets node {$node->name}, which cannot run schedules.",
                    detail: [
                        'schedule' => $schedule->name,
                        'node' => $node->name,
                        'role' => $node->role,
                        'status' => $node->status,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRuntimeAndScheduler(Schedule $schedule, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($schedule->schedule_key);

        if (($observed['runtime_available'] ?? null) !== true) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'schedule.runtime_backend_unavailable',
                    kind: DriftKind::Missing,
                    summary: "Schedule {$schedule->name} target runtime backend is unavailable.",
                    detail: [
                        'schedule' => $schedule->name,
                    ],
                ),
            ];
        }

        $status = is_string($observed['scheduler_status'] ?? null) ? $observed['scheduler_status'] : null;

        if ($status === null || $status === 'missing') {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'schedule.scheduler_missing',
                    kind: DriftKind::Missing,
                    summary: "Orbit Scheduler program is missing for schedule {$schedule->name}.",
                    detail: [
                        'schedule' => $schedule->name,
                    ],
                ),
            ];
        }

        if ($status !== 'running') {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'schedule.scheduler_stopped',
                    kind: DriftKind::Divergent,
                    summary: "Orbit Scheduler program is not running for schedule {$schedule->name}.",
                    detail: [
                        'schedule' => $schedule->name,
                        'observed_status' => $status,
                    ],
                ),
            ];
        }

        return [];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkFreshness(Schedule $schedule, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($schedule->schedule_key);

        if (($observed['runtime_available'] ?? null) !== true || ($observed['scheduler_status'] ?? null) !== 'running') {
            return [];
        }

        $issues = [];
        $heartbeatAt = $this->dateValue($observed['heartbeat_at'] ?? null);
        $registrySyncedAt = $this->dateValue($observed['registry_synced_at'] ?? null);

        if ($heartbeatAt === null || $heartbeatAt->lt(now()->subMinutes(self::FreshnessMinutes))) {
            $issues[] = new DriftEntry(
                family: $this->key(),
                key: 'schedule.heartbeat_stale',
                kind: DriftKind::Divergent,
                summary: "Orbit Scheduler heartbeat is stale for schedule {$schedule->name}.",
                detail: [
                    'schedule' => $schedule->name,
                ],
            );
        }

        if ($registrySyncedAt === null || $registrySyncedAt->lt(now()->subMinutes(self::FreshnessMinutes))) {
            $issues[] = new DriftEntry(
                family: $this->key(),
                key: 'schedule.registry_sync_stale',
                kind: DriftKind::Divergent,
                summary: "Orbit Scheduler registry sync is stale for schedule {$schedule->name}.",
                detail: [
                    'schedule' => $schedule->name,
                ],
            );
        }

        return $issues;
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

    private function normalizeSchedulerStatus(string $status): string
    {
        return match (strtolower($status)) {
            'running' => 'running',
            'missing' => 'missing',
            'stopped', 'exited', 'fatal', 'backoff', 'starting' => 'stopped',
            '' => 'missing',
            default => 'stopped',
        };
    }

    private function dateValue(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return now()->parse($value);
    }
}
