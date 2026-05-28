<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Models\ScheduleRun;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use Carbon\CarbonInterface;
use Throwable;

final readonly class SchedulesProbe
{
    private const int FreshnessMinutes = 10;

    public function __construct(
        private RuntimeBackendProbe $runtimeBackendProbe,
        private NodeRoleAssignments $nodeRoleAssignments = new NodeRoleAssignments,
        private OrbitContainerNames $containerNames = new OrbitContainerNames,
    ) {}

    public function key(): string
    {
        return 'schedule';
    }

    public function label(): string
    {
        return 'Schedules';
    }

    public function introspectGateway(Node $gatewayNode): ProbeSnapshot
    {
        $runtime = $this->runtimeBackendProbe->remoteShell()->run(
            $gatewayNode,
            $this->runtimeInspectionScript(),
            ['timeout' => 15, 'throw' => false],
        );
        $schedulerState = SchedulerState::query()->where('node_id', $gatewayNode->id)->first();
        $runtimeOutput = trim($runtime->output());
        $runtimeAvailable = $runtime->successful();
        $schedulerStatus = $runtimeAvailable
            ? $this->schedulerStatusFromRuntimeInspection($runtimeOutput)
            : null;

        return new ProbeSnapshot([
            'gateway' => [
                'runtime_available' => $runtimeAvailable,
                'runtime_output' => $runtimeOutput,
                'scheduler_status' => $schedulerStatus,
                'heartbeat_at' => $schedulerState?->heartbeat_at?->toISOString(),
            ],
        ]);
    }

    /**
     * @return list<DriftEntry>
     */
    public function diffGateway(Node $gatewayNode, ProbeSnapshot $snapshot): array
    {
        return [
            ...$this->checkGatewayRuntimeAndScheduler($gatewayNode, $snapshot),
            ...$this->checkGatewayFreshness($snapshot),
            ...$this->checkGatewayLockHealth($gatewayNode, $snapshot),
        ];
    }

    public function introspect(Schedule $schedule): ProbeSnapshot
    {
        $node = $this->targetNode($schedule);

        if (! $node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        $targetReachable = true;
        $targetError = null;

        if (! $this->isGatewayNode($node)) {
            try {
                $result = $this->runtimeBackendProbe->remoteShell()->run(
                    $node,
                    'true',
                    ['timeout' => 15, 'throw' => false],
                );
                $targetReachable = $result->successful();
                $targetError = $result->successful() ? null : trim($result->output());
            } catch (Throwable $throwable) {
                $targetReachable = false;
                $targetError = $throwable->getMessage();
            }
        }

        return new ProbeSnapshot([
            $schedule->schedule_key => [
                'target_node' => $node->name,
                'target_reachable' => $targetReachable,
                'target_error' => $targetError,
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
            ...$this->checkTargetReachability($schedule, $snapshot),
            ...$this->checkRunHealth($schedule),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRecordCompleteness(Schedule $schedule): array
    {
        $validScope = in_array($schedule->scope, ['app', 'node', 'orbit'], true);
        $validExecution = in_array($schedule->execution_type, ['command', 'script'], true);

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

        if ($node->status !== 'active' || ! $this->canRunSchedules($node)) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'schedule.target_invalid',
                    kind: DriftKind::Divergent,
                    summary: "Schedule {$schedule->name} targets node {$node->name}, which cannot run schedules.",
                    detail: [
                        'schedule' => $schedule->name,
                        'node' => $node->name,
                        'role' => $node->displayRole(),
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
    private function checkTargetReachability(Schedule $schedule, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get($schedule->schedule_key);

        if (($observed['target_reachable'] ?? null) !== false) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'schedule.target_unreachable',
                kind: DriftKind::Missing,
                summary: "Schedule {$schedule->name} target node is unreachable from the gateway.",
                detail: [
                    'schedule' => $schedule->name,
                    'node' => is_string($observed['target_node'] ?? null) ? $observed['target_node'] : null,
                    'error' => is_string($observed['target_error'] ?? null) ? $observed['target_error'] : null,
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkRunHealth(Schedule $schedule): array
    {
        $latestRun = ScheduleRun::query()
            ->where('schedule_key', $schedule->schedule_key)
            ->latest('started_at')
            ->first();

        if (! $latestRun instanceof ScheduleRun || $latestRun->status !== 'running') {
            return [];
        }

        if ($latestRun->started_at->gte(now()->subMinutes(self::FreshnessMinutes))) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'schedule.run_stuck',
                kind: DriftKind::Divergent,
                summary: "Schedule {$schedule->name} has a stuck running history entry.",
                detail: [
                    'schedule' => $schedule->name,
                    'run_id' => $latestRun->id,
                    'started_at' => $latestRun->started_at->toISOString(),
                ],
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkGatewayRuntimeAndScheduler(Node $gatewayNode, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get('gateway');

        if (($observed['runtime_available'] ?? null) !== true) {
            return [
                new DriftEntry(
                    family: $this->key(),
                    key: 'schedule.runtime_backend_unavailable',
                    kind: DriftKind::Missing,
                    summary: "Gateway scheduler runtime backend is unavailable on node {$gatewayNode->name}.",
                    detail: [
                        'node' => $gatewayNode->name,
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
                    summary: "Orbit Scheduler daemon configuration is missing from gateway node {$gatewayNode->name}.",
                    detail: [
                        'node' => $gatewayNode->name,
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
                    summary: "Orbit Scheduler daemon is not running on gateway node {$gatewayNode->name}.",
                    detail: [
                        'node' => $gatewayNode->name,
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
    private function checkGatewayFreshness(ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get('gateway');

        if (($observed['runtime_available'] ?? null) !== true || ($observed['scheduler_status'] ?? null) !== 'running') {
            return [];
        }

        $heartbeatAt = $this->dateValue($observed['heartbeat_at'] ?? null);

        if ($heartbeatAt !== null && $heartbeatAt->gte(now()->subMinutes(self::FreshnessMinutes))) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'schedule.heartbeat_stale',
                kind: DriftKind::Divergent,
                summary: 'Orbit Scheduler gateway heartbeat is stale.',
            ),
        ];
    }

    /**
     * @return list<DriftEntry>
     */
    private function checkGatewayLockHealth(Node $gatewayNode, ProbeSnapshot $snapshot): array
    {
        $observed = $snapshot->get('gateway');

        if (($observed['runtime_available'] ?? null) !== true || ($observed['scheduler_status'] ?? null) !== 'running') {
            return [];
        }

        $lock = ScheduleLock::query()
            ->where('node_id', $gatewayNode->id)
            ->where(function ($query): void {
                $query
                    ->where('expires_at', '<', now())
                    ->orWhere('locked_at', '<', now()->subMinutes(self::FreshnessMinutes));
            })
            ->orderBy('locked_at')
            ->first();

        if (! $lock instanceof ScheduleLock) {
            return [];
        }

        return [
            new DriftEntry(
                family: $this->key(),
                key: 'schedule.lock_stuck',
                kind: DriftKind::Divergent,
                summary: "Schedule {$lock->schedule_key} has a stale gateway execution lock.",
                detail: [
                    'schedule_key' => $lock->schedule_key,
                    'locked_at' => $lock->locked_at->toISOString(),
                    'expires_at' => $lock->expires_at?->toISOString(),
                ],
            ),
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
            return $this->gatewayNode();
        }

        return null;
    }

    private function gatewayNode(): ?Node
    {
        return $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->first();
    }

    private function canRunSchedules(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeCanServeGatewayOrAppHostWorkloads($node);
    }

    private function isGatewayNode(Node $node): bool
    {
        return $this->nodeRoleAssignments->nodeIsGateway($node);
    }

    private function runtimeInspectionScript(): string
    {
        $container = escapeshellarg($this->containerNames->runtime());
        $format = <<<'FORMAT'
running={{.State.Running}}
restart_policy={{.HostConfig.RestartPolicy.Name}}
{{range .Config.Env}}env={{.}}
{{end}}
FORMAT;

        return implode("\n", [
            'set -e',
            'sudo docker inspect --format '.escapeshellarg($format).' '.$container.' 2>/dev/null',
            "if sudo docker exec {$container} sh -lc ".escapeshellarg("ps -eo args | grep -F 'artisan orbit-scheduler' | grep -v grep >/dev/null").'; then',
            "    printf 'scheduler_running=true\n'",
            'else',
            "    printf 'scheduler_running=false\n'",
            'fi',
        ]);
    }

    private function schedulerStatusFromRuntimeInspection(string $output): string
    {
        if (str_contains($output, 'scheduler_running=true')) {
            return 'running';
        }

        if (! str_contains($output, 'env=ORBIT_IS_GATEWAY=1') && ! str_contains($output, 'env=ORBIT_IS_GATEWAY=true')) {
            return 'missing';
        }

        return 'stopped';
    }

    private function dateValue(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return now()->parse($value);
    }
}
