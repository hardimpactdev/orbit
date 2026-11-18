<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstancePlacement;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The wake's fast path for a frozen container (docs/reference/prepared-restore.md#the-fast-wake). The Gateway writes the Operation's
 * wake request on the Instance's Node with the frozen agent's `hibernation.marker`; the app-dev role's worker on the Node calls the
 * power helper's restore, checks readiness, removes the asleep marker and writes the Operation's outcome files; the Gateway reads
 * them with `hibernation.times`. Anything but a confirmed outcome leaves the wake to its run, in the same Operation.
 */
final readonly class ContainerResume
{
    /** The longest the Gateway observes, the agent calls included: beyond the helper's 2 s restore and the 1.5 s readiness request. */
    public const float BoundSeconds = 6.0;

    /** The app-dev role's worker template; `hibernation.times` tells whether the Node has the fast wake. */
    public const string WorkerPath = '/etc/systemd/system/orbit-instance-wake@.service';

    /** The worker's outcome files, which survive a Node reboot until the Gateway removes them after the projection. */
    public const string OutcomeDirectory = '/var/lib/orbit/hibernation/outcomes';

    /** The ready file's suffix for each restore status: the one status the fast wake transports. */
    public const array ReadyStatuses = ['complete' => 'complete', 'partial' => 'partial', 'skipped' => 'skipped', 'timed-out' => 'timed_out'];

    /** The restore statuses of an attempt that read pages back: the wake's `restore_mode` is then `prepared`. */
    public const array PreparedStatuses = ['complete', 'partial', 'timed_out'];

    /** The removal of an untaken request. */
    private const float RemoveSeconds = 1.0;

    /** How long a Node without the worker is left out of the fast path: its other wakes then cost nothing extra until it converges. */
    public const int MissingScriptSeconds = 600;

    /** The pause between two reads; the first read comes at once. */
    private const float ReadPauseSeconds = 0.025;

    public function __construct(private AgentCommandRunner $agents) {}

    /** The Operation's wake request, in the folder where only root writes; the worker acts on its name. */
    public static function requestPath(int $instanceId, int $operationId, string $suffix = 'wake'): string
    {
        return RuntimeHibernation::MarkerDirectory.'/'.self::base($instanceId, $operationId).'.'.$suffix;
    }

    /** One of the Operation's outcome files: `ready-<status>`, `done` or `failed`. */
    public static function outcomePath(int $instanceId, int $operationId, string $suffix): string
    {
        return self::OutcomeDirectory.'/'.self::base($instanceId, $operationId).'.'.$suffix;
    }

    private static function base(int $instanceId, int $operationId): string
    {
        return RuntimeHibernation::key($instanceId).'-op-'.$operationId;
    }

    /**
     * The Operation's request and outcome files.
     *
     * @return array<string, string>
     */
    public static function files(int $instanceId, int $operationId): array
    {
        $files = ['request' => self::requestPath($instanceId, $operationId), 'taken' => self::requestPath($instanceId, $operationId, 'taken')];
        foreach (array_keys(self::ReadyStatuses) as $suffix) {
            $files['ready-'.$suffix] = self::outcomePath($instanceId, $operationId, 'ready-'.$suffix);
        }

        return [...$files, 'done' => self::outcomePath($instanceId, $operationId, 'done'), 'failed' => self::outcomePath($instanceId, $operationId, 'failed')];
    }

    /**
     * Whether a wake tries the Node's fast wake first: the Instance's last sleep froze it, it is no nested-fleet Instance, and its Node
     * has the worker. Any `swap_subtree` qualifies: the helper's restore closes every limit that the sleep opened.
     *
     * @param  array<string, mixed>  $input
     */
    public function applies(Instance $instance, InstanceRuntime $runtime, array $input): bool
    {
        if ($runtime->power_state !== InstanceRuntimePowerState::Stopped || $runtime->incus_project === InstancePlacement::NestedProject
            || Cache::has(self::missingScriptKey($instance->node_id))) {
            return false;
        }

        return self::frozenSleep($instance) !== null;
    }

    /**
     * Whether the Instance's last sleep froze its container, rather than stopping it or failing, and no wake since found the container
     * stopped (a host reboot ends a freeze): the one rule for a frozen container.
     */
    public static function frozenBySleep(Instance $instance): bool
    {
        return self::frozenSleep($instance) !== null;
    }

    /** The sleep that froze the Instance's container while that freeze still holds; see frozenBySleep(). */
    public static function frozenSleep(Instance $instance): ?Operation
    {
        $sleeps = Operation::query()->where('kind', InstanceSleep::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)->latest('id');
        $sleep = (clone $sleeps)->first();
        if ($sleep !== null && ExecutorPowerIntent::managed($sleep) && $sleep->status === OperationStatus::Failed && $sleep->error_code !== InstanceSleep::IncompleteFreeze) {
            $sleep = $sleeps->where(fn ($query) => $query->where('status', OperationStatus::Succeeded)->orWhere('error_code', InstanceSleep::IncompleteFreeze))->first();
        }
        if ($sleep === null || ($sleep->error_code !== InstanceSleep::IncompleteFreeze
            && ($sleep->status !== OperationStatus::Succeeded || ($sleep->result['sleep'] ?? null) !== 'frozen'))) {
            return null;
        }
        $found = Operation::query()->where('kind', InstanceWake::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->where('id', '>', $sleep->id)->where('error_code', InstanceWake::NestedStopped)->exists();

        return $found ? null : $sleep;
    }

    /**
     * Writes the Operation's wake request and reads its outcome, at once and then every 25 ms. Returns the wake's result fields once
     * the Node's worker wrote `done` beside exactly one ready file, or null when the run must continue the Operation: the Node has no
     * worker, the worker wrote `failed`, an agent call failed, or the bound passed. A request still untaken is removed, so it cannot
     * wake the Instance later. `ready_at` is the ready file's time, in whole seconds; `trigger_seconds` is the request's call and
     * `confirm_seconds` the time until the Gateway read the outcome.
     *
     * @return array{wake: string, marker_found: bool, restore_mode: string, prepared_restore: array{status: string}, ready_at: string,
     *     ready_at_resolution_seconds: int, trigger_seconds: float, confirm_seconds: float}|null
     */
    public function run(Operation $operation, Node $node): ?array
    {
        $paths = [...self::files($operation->target_id, $operation->id), 'worker' => self::WorkerPath];
        $started = self::now();
        $until = $started + self::BoundSeconds;
        try {
            $this->agents->run($node, 'hibernation.marker', ['operation' => 'mark', 'marker_directory' => RuntimeHibernation::MarkerDirectory,
                'log_directory' => RuntimeHibernation::AccessLogDirectory, 'path' => $paths['request']], self::BoundSeconds);
            $marked = self::now();
            while (true) {
                $times = $this->agents->run($node, 'hibernation.times', ['paths' => array_values($paths)], max(0.2, $until - self::now()))['times'] ?? [];
                $at = fn (string $key): ?int => is_array($times) && is_int($times[$paths[$key]] ?? null) ? $times[$paths[$key]] : null;
                // A Node whose app-dev role predates the worker cannot take the request: its wakes skip the fast path for a while.
                if ($at('worker') === null) {
                    Cache::put(self::missingScriptKey($node->id), true, self::MissingScriptSeconds);
                    $this->fallBack($operation, $node, $paths['request'], 'no_worker');

                    return null;
                }
                $ready = array_filter(self::ReadyStatuses, fn (string $status, string $suffix): bool => $at('ready-'.$suffix) !== null, ARRAY_FILTER_USE_BOTH);
                if ($at('done') !== null && count($ready) === 1) {
                    $status = (string) reset($ready);

                    return ['wake' => 'resumed', 'marker_found' => true,
                        'restore_mode' => in_array($status, self::PreparedStatuses, true) ? 'prepared' : 'ordinary',
                        'prepared_restore' => ['status' => $status],
                        'ready_at' => Carbon::createFromTimestampUTC((int) $at('ready-'.array_key_first($ready)))->toIso8601ZuluString(),
                        'ready_at_resolution_seconds' => 1,
                        'trigger_seconds' => round($marked - $started, 3), 'confirm_seconds' => round(self::now() - $marked, 3)];
                }
                // The worker never removes the request's .taken file, so neither file means the request is gone: no worker acts on it.
                $gone = $at('request') === null && $at('taken') === null;
                if ($at('failed') !== null || $gone || self::now() + self::ReadPauseSeconds >= $until) {
                    $this->fallBack($operation, $node, $paths['request'], $at('failed') !== null ? 'declined' : ($gone ? 'gone' : 'bound'));

                    return null;
                }
                Sleep::usleep((int) (self::ReadPauseSeconds * 1_000_000));
            }
        } catch (Throwable $exception) {
            Log::info('The wake falls back to its run', ['operation_id' => $operation->id, 'exception_type' => $exception::class,
                'error_code' => $exception instanceof AgentCommandRefused ? $exception->errorCode : null,
                'runner_failure' => $exception instanceof AgentRunnerFailure ? $exception->kind : null]);
            $this->fallBack($operation, $node, $paths['request'], 'agent');

            return null;
        }
    }

    /**
     * Removes the Operation's request and outcome files once its projection committed. Until then they stay, whatever their age, so
     * a crash before this changes nothing; a failed removal is left for the Instance's destroy.
     */
    public function cleanUp(Operation $operation, Node $node): void
    {
        $paths = self::files($operation->target_id, $operation->id);
        try {
            $times = $this->agents->run($node, 'hibernation.times', ['paths' => array_values($paths)], self::RemoveSeconds)['times'] ?? [];
            foreach ($paths as $path) {
                if (is_array($times) && ($times[$path] ?? null) !== null) {
                    $this->agents->run($node, 'hibernation.marker', ['operation' => 'remove', 'path' => $path], self::RemoveSeconds);
                }
            }
        } catch (Throwable $exception) {
            Log::info('The wake\'s Node files stay for a later cleanup', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
        }
    }

    public static function missingScriptKey(int $nodeId): string
    {
        return 'instances.wake-script-missing.'.$nodeId;
    }

    /** Seconds with microseconds, from the clock that a test's faked sleep moves. */
    public static function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }

    /** Removes a request the Node did not take, so a late worker cannot resume the container beside the run. */
    private function fallBack(Operation $operation, Node $node, string $request, string $reason): void
    {
        Log::info('The wake falls back to its run', ['operation_id' => $operation->id, 'reason' => $reason]);
        try {
            $this->agents->run($node, 'hibernation.marker', ['operation' => 'remove', 'path' => $request], self::RemoveSeconds);
        } catch (Throwable $exception) {
            // The worker declines a request older than one second, so a request left here wakes nothing later.
            Log::warning('The wake request could not be removed', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
        }
    }
}
