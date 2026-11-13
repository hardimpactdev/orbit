<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\Process;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\Builder;

/**
 * The idle sleep of development containers (docs/reference/development-containers.md#sleep-and-wake). The runtime hibernator calls it
 * every sweep. An Instance sleeps after one full idle window of watching in which it had no HTTP activity, no wake and no agent activity,
 * and no turn runs. The watch starts the first time an acting run reads the Instance: an access log written before then, or a site
 * without one, is no evidence of idleness by itself. A Node or a T3 server that cannot be read keeps an Instance awake. Each sleep is an
 * `instance.sleep` Operation triggered by the sweep, at most a few per run.
 */
final readonly class ContainerIdleSweep
{
    public const string TriggeredBy = 'hibernation-sweep';

    /**
     * @param  iterable<InstanceAgentActivity>  $agents
     * @param  list<int>  $neverSleep
     */
    public function __construct(
        private NodeFileTimes $files,
        private InstanceSleep $sleep,
        #[Tag(InstanceAgentActivity::class)] private iterable $agents,
        private int $idleSeconds = RuntimeHibernation::DefaultIdleSeconds,
        private int $sleepsPerRun = 5,
        private array $neverSleep = [],
    ) {}

    /**
     * @param  list<int>|null  $only  Instance ids to consider; null considers every container Instance.
     * @return list<ContainerIdleDecision>
     */
    public function run(bool $dryRun, ?array $only = null, ?int $idleSeconds = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $window = $idleSeconds ?? $this->idleSeconds;
        $runtimes = InstanceRuntime::query()
            ->when($only !== null, static fn (Builder $query) => $query->whereIn('instance_id', $only))
            ->with(['instance.node.roles', 'instance.project', 'instance.processes', 'instance.nodeServiceTargets'])
            ->orderBy('instance_id')->get();

        $decisions = [];
        $watched = [];
        foreach ($runtimes as $runtime) {
            $instance = $runtime->instance;
            $reason = $this->standing($instance, $runtime);
            if ($reason !== null) {
                $decisions[$instance->id] = ContainerIdleDecision::skip($instance, $reason);

                continue;
            }
            $watched[$instance->node_id][] = $runtime;
        }

        $sleeps = 0;
        foreach ($watched as $group) {
            $paths = array_map(static fn (InstanceRuntime $runtime): string => RuntimeHibernation::accessLogPath(RuntimeHibernation::key($runtime->instance_id)), $group);
            $times = $this->files->read($group[0]->instance->node, $paths);
            foreach ($group as $index => $runtime) {
                $instance = $runtime->instance;
                if ($times === null) {
                    $decisions[$instance->id] = ContainerIdleDecision::skip($instance, 'http_unreadable');

                    continue;
                }
                $evidence = $this->evidence($runtime, $times[$paths[$index]] ?? null, $dryRun, $now);
                $reason = $this->reason($evidence, $now, $window);
                if ($reason === null && $sleeps >= $this->sleepsPerRun) {
                    $reason = 'next_run';
                }
                if ($reason !== null) {
                    $decisions[$instance->id] = $evidence->with('skip', $reason);

                    continue;
                }
                $sleeps++;
                if ($dryRun) {
                    $decisions[$instance->id] = $evidence->with('sleep', 'idle');

                    continue;
                }
                try {
                    $decisions[$instance->id] = $evidence->with('sleep', 'idle', $this->sleep->start($instance, self::TriggeredBy, null)->id);
                } catch (ResourceOperationException $error) {
                    $decisions[$instance->id] = $evidence->with('skip', 'refused:'.$error->errorCode);
                }
            }
        }
        ksort($decisions);

        return array_values($decisions);
    }

    /** The reasons that need no read of the Node or the container, in the order the sleep admission checks them. */
    private function standing(Instance $instance, InstanceRuntime $runtime): ?string
    {
        return match (true) {
            $instance->name === 'default' => 'default',
            $runtime->incus_project === InstancePlacement::NestedProject || $instance->project->nested_fleet => 'nested_fleet',
            $instance->placementEnvironment() !== 'development' => 'not_development',
            in_array($instance->id, $this->neverSleep, true) => 'never_sleep',
            $runtime->power_state !== InstanceRuntimePowerState::Running => 'asleep',
            $instance->status !== InstanceState::Active || $this->hasOpenOperation($instance) => 'busy',
            InstancePower::hasActiveTask($instance) => 'task',
            $instance->processes->contains(static fn (Process $process): bool => $process->keep_alive
                && $process->desired_state === DesiredProcessState::Running) => 'keep_alive',
            // A Node service, such as PowerSync, may call back into the Instance through its Route: a frozen app cannot answer it.
            $instance->nodeServiceTargets->isNotEmpty() => 'node_service',
            default => null,
        };
    }

    /** Every activity the sweep knows of one watched Instance. An acting run starts the watch of an Instance it reads for the first time. */
    private function evidence(InstanceRuntime $runtime, ?int $log, bool $dryRun, CarbonImmutable $now): ContainerIdleDecision
    {
        $instance = $runtime->instance;
        $watch = $runtime->idle_watch_started_at?->getTimestamp();
        if ($watch === null && ! $dryRun) {
            $runtime->forceFill(['idle_watch_started_at' => $now])->save();
        }
        $wake = Operation::query()->where('kind', InstanceWake::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereNotNull('finished_at')->max('finished_at');
        $activity = null;
        foreach ($this->agents as $agent) {
            $activity = $agent->read($instance) ?? $activity;
        }

        return new ContainerIdleDecision($instance->id, $instance->name, 'skip', '', null, $watch, $log,
            $activity ?? AgentActivity::unreadable(), is_string($wake) ? CarbonImmutable::parse($wake)->getTimestamp() : null);
    }

    /** The first reason that keeps a watched Instance awake, or null when it may sleep. A watch that starts now reads as `watch_starting`. */
    private function reason(ContainerIdleDecision $evidence, CarbonImmutable $now, int $window): ?string
    {
        $recent = static fn (?int $time): bool => $time !== null && $now->getTimestamp() - $time < $window;
        $t3 = $evidence->t3;

        return match (true) {
            $evidence->watchStartedAt === null => 'watch_starting',
            $recent($evidence->watchStartedAt) => 'watching',
            $recent($evidence->lastHttpAt) => 'http_active',
            $recent($evidence->lastWakeAt) => 'woke_recently',
            $t3 === null || ! $t3->readable => 't3_unreadable',
            $t3->turnRuns => 't3_turn_running',
            $recent($t3->lastActivityUnix) => 't3_active',
            default => null,
        };
    }

    private function hasOpenOperation(Instance $instance): bool
    {
        return Operation::query()->whereNotIn('status', OperationStatus::terminalValues())
            ->where(static fn (Builder $query) => $query
                ->where(static fn (Builder $query) => $query->where('target_type', 'instance')->where('target_id', $instance->id))
                ->orWhere(static fn (Builder $query) => $query->where('target_type', 'process')->whereIn('target_id', $instance->processes()->select('id'))))
            ->exists();
    }
}
