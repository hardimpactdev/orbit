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
 * every sweep. An Instance sleeps when it had no HTTP activity, no wake and no agent activity for the idle window, and no turn runs.
 * Missing or unreadable activity keeps an Instance awake: a container site without its access log, a Node that does not answer, a
 * T3 server that cannot be read. Each sleep is an `instance.sleep` Operation triggered by the sweep, at most a few per run.
 */
final readonly class ContainerIdleSweep
{
    public const string TriggeredBy = 'hibernation-sweep';

    /** @param iterable<InstanceAgentActivity> $agents */
    public function __construct(
        private NodeFileTimes $files,
        private InstanceSleep $sleep,
        #[Tag(InstanceAgentActivity::class)] private iterable $agents,
        private int $idleSeconds = RuntimeHibernation::DefaultIdleSeconds,
        private int $sleepsPerRun = 5,
        /** @var list<int> */
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
            ->with(['instance.node.roles', 'instance.project', 'instance.processes'])
            ->orderBy('instance_id')->get();

        $decisions = [];
        $http = [];
        foreach ($runtimes as $runtime) {
            $instance = $runtime->instance;
            $reason = $this->standing($instance, $runtime);
            if ($reason !== null) {
                $decisions[$instance->id] = ContainerIdleDecision::skip($instance, $reason);

                continue;
            }
            $http[$instance->node_id][] = $instance;
        }

        $candidates = [];
        foreach ($http as $instances) {
            $node = $instances[0]->node;
            $paths = array_map(static fn (Instance $instance): string => RuntimeHibernation::accessLogPath(RuntimeHibernation::key($instance->id)), $instances);
            $times = $this->files->read($node, $paths);
            foreach ($instances as $index => $instance) {
                $reason = $this->httpReason($times === null ? false : ($times[$paths[$index]] ?? null), $now, $window)
                    ?? $this->wakeReason($instance, $now, $window);
                if ($reason !== null) {
                    $decisions[$instance->id] = ContainerIdleDecision::skip($instance, $reason);

                    continue;
                }
                $candidates[] = $instance;
            }
        }

        $sleeps = 0;
        foreach ($candidates as $instance) {
            $reason = $this->agentReason($instance, $now, $window);
            if ($reason !== null) {
                $decisions[$instance->id] = ContainerIdleDecision::skip($instance, $reason);

                continue;
            }
            if ($sleeps >= $this->sleepsPerRun) {
                $decisions[$instance->id] = ContainerIdleDecision::skip($instance, 'next_run');

                continue;
            }
            $sleeps++;
            if ($dryRun) {
                $decisions[$instance->id] = ContainerIdleDecision::sleep($instance, null);

                continue;
            }
            try {
                $decisions[$instance->id] = ContainerIdleDecision::sleep($instance, $this->sleep->start($instance, self::TriggeredBy, null)->id);
            } catch (ResourceOperationException $error) {
                $decisions[$instance->id] = ContainerIdleDecision::skip($instance, 'refused:'.$error->errorCode);
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
            default => null,
        };
    }

    /** @param  int|false|null  $time  false when the Node did not answer, null when the site has no access log yet */
    private function httpReason(int|false|null $time, CarbonImmutable $now, int $window): ?string
    {
        return match (true) {
            $time === false => 'http_unreadable',
            $time === null => 'http_unknown',
            $now->getTimestamp() - $time < $window => 'http_active',
            default => null,
        };
    }

    /** A wake counts as activity: a visit that woke the Instance gets a full window before the next sleep. */
    private function wakeReason(Instance $instance, CarbonImmutable $now, int $window): ?string
    {
        $woke = Operation::query()->where('kind', InstanceWake::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->where('finished_at', '>', $now->subSeconds($window))->exists();

        return $woke ? 'woke_recently' : null;
    }

    private function agentReason(Instance $instance, CarbonImmutable $now, int $window): ?string
    {
        $activity = null;
        foreach ($this->agents as $agent) {
            $activity = $agent->read($instance) ?? $activity;
        }

        return match (true) {
            $activity === null || ! $activity->readable => 't3_unreadable',
            $activity->turnRuns => 't3_turn_running',
            $activity->lastActivityUnix !== null && $now->getTimestamp() - $activity->lastActivityUnix < $window => 't3_active',
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
