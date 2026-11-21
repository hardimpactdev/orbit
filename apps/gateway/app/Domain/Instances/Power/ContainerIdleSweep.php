<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationKinds;
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
 * every sweep. An Instance sleeps after one full idle window of watching in which it had no HTTP activity, no wake, no deployment and,
 * with a T3 server, no agent activity and no running turn. A default and a nested-fleet Instance follow the same rule: only data keeps
 * an Instance awake. The watch starts the first time an acting run reads the Instance: an access log written before then, or a site
 * without one, is no evidence of idleness by itself. A Node or a T3 server that cannot be read keeps an Instance awake; an Instance
 * without a T3 server has none to read. Each sleep is an `instance.sleep` Operation triggered by the sweep, at most a few per run.
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
     * The sweep under one value of the setting: a dry run changes nothing, an acting run starts the watch and the sleeps.
     *
     * @param  list<int>|null  $only  Instance ids to consider; null considers every container Instance.
     * @return list<ContainerIdleDecision>
     */
    public function run(bool $dryRun, ?array $only = null, ?int $idleSeconds = null, ?CarbonImmutable $now = null,
        ContainerSleepScope $scope = ContainerSleepScope::All): array
    {
        return $this->sweep($dryRun, $only, $idleSeconds, $now, [$scope])[$scope->value];
    }

    /**
     * A dry run under every value of the setting, from one read of each Node and each T3 server.
     *
     * @param  list<int>|null  $only
     * @return array<string, list<ContainerIdleDecision>> The decisions keyed by the value.
     */
    public function preview(?array $only = null, ?int $idleSeconds = null, ?CarbonImmutable $now = null): array
    {
        return $this->sweep(true, $only, $idleSeconds, $now, ContainerSleepScope::cases());
    }

    /**
     * @param  list<int>|null  $only
     * @param  list<ContainerSleepScope>  $scopes  One value for an acting run.
     * @return array<string, list<ContainerIdleDecision>>
     */
    private function sweep(bool $dryRun, ?array $only, ?int $idleSeconds, ?CarbonImmutable $now, array $scopes): array
    {
        $now ??= CarbonImmutable::now();
        $window = $idleSeconds ?? $this->idleSeconds;
        $runtimes = InstanceRuntime::query()
            ->when($only !== null, static fn (Builder $query) => $query->whereIn('instance_id', $only))
            ->with(['instance.node.roles', 'instance.project', 'instance.processes', 'instance.nodeServiceTargets'])
            ->orderBy('instance_id')->get();

        $standing = [];
        $watched = [];
        foreach ($runtimes as $runtime) {
            $standing[$runtime->instance_id] = array_map(fn (ContainerSleepScope $scope): ?string => $this->standing($runtime->instance, $runtime, $scope), $scopes);
            // A Node and a T3 server are read only for an Instance that some value may put to sleep.
            if (in_array(null, $standing[$runtime->instance_id], true)) {
                $watched[$runtime->instance->node_id][] = $runtime;
            }
        }

        $evidence = [];
        foreach ($watched as $group) {
            $paths = array_map(static fn (InstanceRuntime $runtime): string => RuntimeHibernation::accessLogPath(RuntimeHibernation::key($runtime->instance_id)), $group);
            $times = $this->files->read($group[0]->instance->node, $paths);
            foreach ($group as $index => $runtime) {
                $evidence[$runtime->instance_id] = $times === null ? null : $this->evidence($runtime, $times[$paths[$index]] ?? null, $dryRun, $now);
            }
        }

        $decisions = [];
        foreach ($scopes as $index => $scope) {
            $sleeps = 0;
            $rows = [];
            foreach ($runtimes as $runtime) {
                $rows[] = $this->decide($runtime->instance, $standing[$runtime->instance_id][$index],
                    $evidence[$runtime->instance_id] ?? null, $dryRun, $now, $window, $sleeps);
            }
            $decisions[$scope->value] = $rows;
        }

        return $decisions;
    }

    /** One row under one value. `$evidence` is null when the Instance's Node did not answer: a row with no standing reason was read. */
    private function decide(Instance $instance, ?string $standing, ?ContainerIdleDecision $evidence, bool $dryRun, CarbonImmutable $now,
        int $window, int &$sleeps): ContainerIdleDecision
    {
        if ($standing !== null) {
            return ContainerIdleDecision::skip($instance, $standing);
        }
        if ($evidence === null) {
            return ContainerIdleDecision::skip($instance, 'http_unreadable');
        }
        $reason = $this->reason($evidence, $now, $window);
        if ($reason === null && $sleeps >= $this->sleepsPerRun) {
            $reason = 'next_run';
        }
        if ($reason !== null) {
            return $evidence->with('skip', $reason);
        }
        $sleeps++;
        if ($dryRun) {
            return $evidence->with('sleep', 'idle');
        }
        try {
            return $evidence->with('sleep', 'idle', $this->sleep->start($instance, self::TriggeredBy, null)->id);
        } catch (ResourceOperationException $error) {
            return $evidence->with('skip', 'refused:'.$error->errorCode);
        }
    }

    /** True restores an unshared wake, false keeps a recognized consumer or policy, and null means the evidence is missing. */
    public function canRestoreWake(Instance $instance, Operation $wake): ?bool
    {
        $runtime = $instance->runtime()->first();
        $since = $wake->started_at?->getTimestamp();
        if ($runtime === null || $since === null || $wake->finished_at === null) {
            return null;
        }
        if (! $this->wakeStillRestorable($instance, $runtime, $wake)) {
            return false;
        }

        $path = RuntimeHibernation::accessLogPath(RuntimeHibernation::key($instance->id));
        $times = $this->files->read($instance->node, [$path]);
        if ($times === null) {
            return null;
        }
        $evidence = $this->evidence($runtime, $times[$path] ?? null, true, CarbonImmutable::now());
        if ($evidence->t3Server && ($evidence->t3 === null || ! $evidence->t3->readable)) {
            return null;
        }
        $used = static fn (?int $time): bool => $time !== null && $time >= $since;

        return ! ($used($evidence->lastHttpAt) || $used($evidence->lastDeployAt)
            || $evidence->t3?->turnRuns === true || $used($evidence->t3?->lastActivityUnix));
    }

    /** The recorded conditions can be checked again under the Instance admission lock. */
    public function wakeStillRestorable(Instance $instance, InstanceRuntime $runtime, Operation $wake): bool
    {
        $latest = Operation::query()->whereIn('kind', [InstanceWake::Kind, InstanceStart::Kind])
            ->where('target_type', 'instance')->where('target_id', $instance->id)->latest('id')->value('id');

        return $latest === $wake->id && $this->standing($instance, $runtime, ContainerSleepScope::All) === null;
    }

    /** The reasons that need no read of the Node or the container: the value's own first, then in the order the sleep admission checks them. */
    private function standing(Instance $instance, InstanceRuntime $runtime, ContainerSleepScope $scope): ?string
    {
        return match (true) {
            ($excluded = $scope->excludes($runtime)) !== null => $excluded,
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
        // A wake of a nested-fleet clone that no sleep froze is its start (InstanceStart): its end counts as a wake's.
        $wake = Operation::query()->whereIn('kind', [InstanceWake::Kind, InstanceStart::Kind])->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereNotNull('finished_at')->max('finished_at');
        // An automatic deploy of a default is no activity: a new commit alone keeps no default awake.
        $deploy = Operation::query()->where('kind', OperationKinds::InstanceDeploy)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->where(static fn ($query) => $query->whereNull('triggered_by')->orWhereNotIn('triggered_by', AutomaticDeployHistory::Triggers))
            ->whereNotNull('finished_at')->max('finished_at');
        // An Instance without a T3 server, such as a default, has nothing to read: the T3 rows do not apply to it.
        $server = $runtime->t3_port !== null;
        $activity = null;
        foreach ($server ? $this->agents : [] as $agent) {
            $activity = $agent->read($instance) ?? $activity;
        }

        return new ContainerIdleDecision($instance->id, $instance->name, 'skip', '', null, $watch, $log,
            $server ? $activity ?? AgentActivity::unreadable() : null, self::unix($wake), self::unix($deploy), $server);
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
            // A release refresh resumes a sleeping default: it gets a full window before the next freeze.
            $recent($evidence->lastDeployAt) => 'deployed_recently',
            ! $evidence->t3Server => null,
            $t3 === null || ! $t3->readable => 't3_unreadable',
            $t3->turnRuns => 't3_turn_running',
            $recent($t3->lastActivityUnix) => 't3_active',
            default => null,
        };
    }

    private static function unix(mixed $time): ?int
    {
        return is_string($time) ? CarbonImmutable::parse($time)->getTimestamp() : null;
    }

    private function hasOpenOperation(Instance $instance): bool
    {
        return Operation::query()->exceptFleetWait()->whereNotIn('status', OperationStatus::terminalValues())
            ->where(static fn (Builder $query) => $query
                ->where(static fn (Builder $query) => $query->where('target_type', 'instance')->where('target_id', $instance->id))
                ->orWhere(static fn (Builder $query) => $query->where('target_type', 'process')->whereIn('target_id', $instance->processes()->select('id'))))
            ->exists();
    }
}
