<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\Doctor\DriftEntry;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\ScheduleRun;
use App\Services\Gateway\GatewayImageReference;
use App\Services\Gateway\GatewaySwarmManager;
use App\Services\Gateway\GatewaySwarmStackRenderer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use RuntimeException;

final readonly class SchedulesFixer
{
    private const string Stack = 'orbit';

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments = new NodeRoleAssignments,
        private GatewaySwarmManager $swarm = new GatewaySwarmManager,
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

        if ($entry->key === 'schedule.scheduler_image_mismatch') {
            $this->restoreGatewayDaemonImage($this->schedulerStackService(), $entry, 'scheduler');

            return $this->action($gatewayNode, $entry, $schedule);
        }

        if ($entry->key === 'schedule.runtime_hibernator_image_mismatch') {
            $this->restoreGatewayDaemonImage($this->runtimeHibernatorStackService(), $entry, 'runtime hibernator');

            return $this->action($gatewayNode, $entry, $schedule);
        }

        if ($entry->key === 'schedule.scheduler_missing') {
            $this->restoreMissingGatewayDaemon($this->schedulerStackService());

            return $this->action($gatewayNode, $entry, $schedule);
        }

        if ($entry->key === 'schedule.runtime_hibernator_missing') {
            $this->restoreMissingGatewayDaemon($this->runtimeHibernatorStackService());

            return $this->action($gatewayNode, $entry, $schedule);
        }

        if (in_array(
            $entry->key,
            ['schedule.scheduler_stopped', 'schedule.scheduler_replicas_mismatch'],
            strict: true,
        )) {
            $this->restoreGatewayDaemon($this->schedulerStackService(), 'scheduler');

            return $this->action($gatewayNode, $entry, $schedule);
        }

        if (in_array(
            $entry->key,
            [
                'schedule.runtime_hibernator_stopped',
                'schedule.runtime_hibernator_replicas_mismatch',
            ],
            strict: true,
        )) {
            $this->restoreGatewayDaemon($this->runtimeHibernatorStackService(), 'runtime hibernator');

            return $this->action($gatewayNode, $entry, $schedule);
        }

        return null;
    }

    private function restoreMissingGatewayDaemon(string $service): void
    {
        if ($this->swarm->serviceImage($service) !== null) {
            $this->swarm->scaleService($service, 1);

            return;
        }

        $this->convergeGatewayStack();
    }

    private function convergeGatewayStack(): void
    {
        $configRoot = config('orbit.paths.config_root');

        if (! is_string($configRoot) || trim($configRoot) === '') {
            throw new RuntimeException('Orbit config root is unavailable for gateway stack convergence.');
        }

        $this->swarm->deployStack(
            rtrim($configRoot, '/').'/swarm/'.GatewaySwarmManager::StackFile,
            self::Stack,
        );
    }

    private function restoreGatewayDaemon(string $service, string $label): void
    {
        if ($this->swarm->serviceImage($service) === null) {
            throw new RuntimeException("Gateway {$label} Swarm service [{$service}] is missing.");
        }

        $this->swarm->scaleService($service, 1);
    }

    private function restoreGatewayDaemonImage(string $service, DriftEntry $entry, string $label): void
    {
        $expectedImage = is_string($entry->detail['expected_image'] ?? null)
            ? $entry->detail['expected_image']
            : config('orbit.updates.gateway_image');

        if (! is_string($expectedImage) || trim($expectedImage) === '') {
            throw new RuntimeException("Configured gateway image is unavailable for {$label} image repair.");
        }

        $this->swarm->updateServiceImage($service, GatewayImageReference::fromString($expectedImage), 'stop-first');
        $this->swarm->scaleService($service, 1);
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
            'details' => array_filter(
                [
                    'schedule' => $schedule?->name,
                    ...($entry->detail ?? []),
                ],
                fn (mixed $value): bool => $value !== null,
            ),
        ];
    }

    private function gatewayNode(): ?Node
    {
        return $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->first();
    }

    private function schedulerStackService(): string
    {
        return self::Stack.'_'.GatewaySwarmStackRenderer::SchedulerService;
    }

    private function runtimeHibernatorStackService(): string
    {
        return self::Stack.'_'.GatewaySwarmStackRenderer::RUNTIME_HIBERNATOR_SERVICE;
    }
}
