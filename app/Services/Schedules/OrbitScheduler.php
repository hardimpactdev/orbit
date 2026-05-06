<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Contracts\RemoteShell;
use App\Data\Schedules\SchedulerTickResult;
use App\Http\Gateway\GatewayConnector;
use App\Http\Gateway\Requests\Schedules\StoreSchedulerHeartbeatRequest;
use App\Http\Gateway\Requests\Schedules\StoreScheduleRunRequest;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleLock;
use App\Models\SchedulerState;
use App\Models\ScheduleRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

final readonly class OrbitScheduler
{
    public function __construct(
        private RemoteShell $remoteShell,
        private ScheduleInterval $interval,
        private ScheduleRunHistoryHookRenderer $hookRenderer,
    ) {}

    public function tick(?CarbonImmutable $now = null): SchedulerTickResult
    {
        $startedAt = $now ?? CarbonImmutable::now();

        $localNode = $this->localNode();

        if (! $localNode instanceof Node) {
            return new SchedulerTickResult(
                startedAt: $startedAt,
                finishedAt: CarbonImmutable::now(),
                dueSchedules: 0,
                executedSchedules: 0,
            );
        }

        $this->recordHeartbeat($localNode, $startedAt);

        $dueSchedules = $this->dueSchedules($localNode, $startedAt);
        $executedSchedules = 0;

        foreach ($dueSchedules as $schedule) {
            if (! $this->claimLock($localNode, $schedule, $startedAt)) {
                continue;
            }

            try {
                $this->runSchedule($localNode, $schedule);
                $executedSchedules++;
            } finally {
                $this->releaseLock($localNode, $schedule);
            }
        }

        return new SchedulerTickResult(
            startedAt: $startedAt,
            finishedAt: CarbonImmutable::now(),
            dueSchedules: count($dueSchedules),
            executedSchedules: $executedSchedules,
        );
    }

    public function secondsUntilNextMinute(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $seconds = (int) $now->format('s');

        if ($seconds === 0) {
            return 60;
        }

        return 60 - $seconds;
    }

    private function localNode(): ?Node
    {
        return Node::query()
            ->where('is_local', true)
            ->where('status', 'active')
            ->first();
    }

    /**
     * @return list<Schedule>
     */
    private function dueSchedules(Node $localNode, CarbonImmutable $now): array
    {
        return Schedule::query()
            ->with(['app.node', 'node'])
            ->where('enabled', true)
            ->where('status', 'expected')
            ->get()
            ->filter(fn (Schedule $schedule): bool => $this->targetsLocalNode($schedule, $localNode))
            ->filter(fn (Schedule $schedule): bool => $this->interval->isDue($schedule->interval, $schedule->timezone, $now))
            ->values()
            ->all();
    }

    private function targetsLocalNode(Schedule $schedule, Node $localNode): bool
    {
        if ($schedule->scope === 'app') {
            return $schedule->app?->node?->id === $localNode->id;
        }

        if ($schedule->scope === 'node') {
            return $schedule->node?->id === $localNode->id;
        }

        return $schedule->scope === 'orbit' && $localNode->role === 'gateway';
    }

    private function claimLock(Node $localNode, Schedule $schedule, CarbonImmutable $now): bool
    {
        try {
            ScheduleLock::query()->create([
                'node_id' => $localNode->id,
                'schedule_key' => $schedule->schedule_key,
                'owner_token' => $this->ownerToken($schedule, $now),
                'locked_at' => $now,
                'expires_at' => $now->addMinutes(15),
            ]);

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    private function releaseLock(Node $localNode, Schedule $schedule): void
    {
        ScheduleLock::query()
            ->where('node_id', $localNode->id)
            ->where('schedule_key', $schedule->schedule_key)
            ->delete();
    }

    private function runSchedule(Node $localNode, Schedule $schedule): void
    {
        $startedAt = CarbonImmutable::now();
        $result = $this->remoteShell->run(
            node: $localNode,
            script: $this->hookRenderer->path($schedule),
            options: ['timeout' => 900],
        );
        $finishedAt = CarbonImmutable::now();
        $status = $result->successful() ? 'completed' : 'failed';

        if ($localNode->role === 'gateway') {
            ScheduleRun::query()->create([
                'node_id' => $localNode->id,
                'schedule_key' => $schedule->schedule_key,
                'status' => $status,
                'exit_code' => $result->exitCode,
                'stdout' => $result->stdout,
                'stderr' => $result->stderr,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            return;
        }

        GatewayConnector::forScheduler()
            ->send(new StoreScheduleRunRequest(
                scheduleKey: $schedule->schedule_key,
                status: $status,
                exitCode: $result->exitCode,
                stdout: $result->stdout,
                stderr: $result->stderr,
                startedAt: $startedAt->toIso8601String(),
                finishedAt: $finishedAt->toIso8601String(),
            ));
    }

    private function recordHeartbeat(Node $localNode, CarbonImmutable $heartbeatAt): void
    {
        if ($localNode->role === 'gateway') {
            SchedulerState::query()->updateOrCreate(
                ['node_id' => $localNode->id],
                [
                    'heartbeat_at' => $heartbeatAt,
                    'registry_synced_at' => $heartbeatAt,
                ],
            );

            return;
        }

        GatewayConnector::forScheduler()
            ->send(new StoreSchedulerHeartbeatRequest(
                heartbeatAt: $heartbeatAt->toIso8601String(),
                registrySyncedAt: $heartbeatAt->toIso8601String(),
            ));
    }

    private function ownerToken(Schedule $schedule, CarbonImmutable $now): string
    {
        return hash('sha256', $schedule->schedule_key.'|'.$now->toIso8601String());
    }
}
