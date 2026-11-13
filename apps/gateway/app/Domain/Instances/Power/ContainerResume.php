<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The wake's fast path for a frozen container (docs/reference/development-containers.md#the-node-resumes-a-frozen-container).
 * The Gateway writes a wake request on the Instance's Node with the frozen agent's `hibernation.marker`; the app-dev role's
 * script on the Node resumes the container and removes the asleep marker; the Gateway reads the outcome with `hibernation.times`.
 * Anything but a confirmed resume leaves the wake to its normal workflow, in the same Operation.
 */
final readonly class ContainerResume
{
    /** The longest the Gateway waits, both agent calls included; a visitor gets the app or the waiting page within it. */
    public const float BoundSeconds = 3.0;

    /** A request the Node has not taken by then finds no script on the Node (its role predates it): the workflow runs. */
    public const float TakeSeconds = 0.6;

    /** The removal of an untaken request: the whole fast path stays within about 4.5 s of Gateway time. */
    private const float RemoveSeconds = 1.0;

    /** How long a Node whose script took no request is left out of the fast path: its wakes then cost nothing extra until it converges. */
    public const int MissingScriptSeconds = 600;

    /** The script needs about half a second after the request; the first read waits that long, later reads come 0.1 s apart. */
    private const float FirstReadSeconds = 0.35;

    private const float ReadPauseSeconds = 0.1;

    public function __construct(private AgentCommandRunner $agents) {}

    /** The wake request, in the folder where only root writes; the script acts on its name. */
    public static function requestPath(int $instanceId, string $suffix = 'wake'): string
    {
        return RuntimeHibernation::MarkerDirectory.'/'.RuntimeHibernation::key($instanceId).'.'.$suffix;
    }

    /**
     * Whether a wake tries the Node's resume first: the Instance's last sleep froze it and its site has a web unit to answer.
     *
     * @param  array<string, mixed>  $input
     */
    public function applies(Instance $instance, InstanceRuntime $runtime, array $input): bool
    {
        if ($runtime->power_state !== InstanceRuntimePowerState::Stopped || (ValidatedData::object($input['container'])['web_config'] ?? null) === null
            || Cache::has(self::missingScriptKey($instance->node_id))) {
            return false;
        }
        $sleep = Operation::query()->where('kind', InstanceSleep::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->orderByDesc('id')->first();

        return $sleep?->status === OperationStatus::Succeeded && ($sleep->result['sleep'] ?? null) === 'frozen';
    }

    /**
     * Writes the wake request and reads its outcome. Returns the wake's result fields once the Node resumed the container and
     * removed its marker, or null when the normal workflow must finish the wake: no script took the request, the script found the
     * container in another state or FrankenPHP silent, an agent call failed, or the bound passed. An untaken request is removed,
     * so it cannot wake the Instance later. `trigger_seconds` is the request's call; `confirm_seconds` the time until the Gateway
     * read the resume.
     *
     * @return array{wake: string, marker_found: bool, trigger_seconds: float, confirm_seconds: float}|null
     */
    public function run(Operation $operation, Node $node): ?array
    {
        $id = $operation->target_id;
        $request = self::requestPath($id);
        $paths = ['request' => $request, 'taken' => self::requestPath($id, 'taken'), 'resumed' => self::requestPath($id, 'resumed'),
            'marker' => ValidatedData::string($operation->input['marker'] ?? null)];
        $started = self::now();
        $until = $started + self::BoundSeconds;
        try {
            $this->agents->run($node, 'hibernation.marker', ['operation' => 'mark', 'marker_directory' => RuntimeHibernation::MarkerDirectory,
                'log_directory' => RuntimeHibernation::AccessLogDirectory, 'path' => $request], self::BoundSeconds);
            $marked = self::now();
            $trigger = $marked - $started;
            Sleep::usleep((int) (self::FirstReadSeconds * 1_000_000));
            while (true) {
                $times = $this->agents->run($node, 'hibernation.times', ['paths' => array_values($paths)], max(0.2, $until - self::now()))['times'] ?? [];
                $at = fn (string $key): mixed => is_array($times) ? ($times[$paths[$key]] ?? null) : null;
                // The script removes an old `.resumed` when it takes the request, and the marker of a sleeping Instance is there
                // until a resume: both together are this resume. The Node's clock is never compared with the Gateway's.
                if ($at('resumed') !== null && $at('marker') === null) {
                    return ['wake' => 'resumed', 'marker_found' => true, 'trigger_seconds' => round($trigger, 3),
                        'confirm_seconds' => round(self::now() - $marked, 3)];
                }
                $untaken = $at('request') !== null && self::now() - $marked >= self::TakeSeconds;
                $declined = $at('request') === null && $at('taken') === null;
                if ($untaken) {
                    Cache::put(self::missingScriptKey($node->id), true, self::MissingScriptSeconds);
                }
                if ($untaken || $declined || self::now() + self::ReadPauseSeconds >= $until) {
                    $this->fallBack($operation, $node, $request, $untaken ? 'untaken' : ($declined ? 'declined' : 'bound'));

                    return null;
                }
                Sleep::usleep((int) (self::ReadPauseSeconds * 1_000_000));
            }
        } catch (Throwable $exception) {
            Log::info('The wake falls back to its workflow', ['operation_id' => $operation->id, 'exception_type' => $exception::class,
                'error_code' => $exception instanceof AgentCommandRefused ? $exception->errorCode : null,
                'runner_failure' => $exception instanceof AgentRunnerFailure ? $exception->kind : null]);
            $this->fallBack($operation, $node, $request, 'agent');

            return null;
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

    /** Removes a request the Node did not take, so a late script start cannot resume the container beside the workflow. */
    private function fallBack(Operation $operation, Node $node, string $request, string $reason): void
    {
        Log::info('The wake falls back to its workflow', ['operation_id' => $operation->id, 'reason' => $reason]);
        try {
            $this->agents->run($node, 'hibernation.marker', ['operation' => 'remove', 'path' => $request], self::RemoveSeconds);
        } catch (Throwable $exception) {
            // The script ignores a request older than one second, so a request left here wakes nothing later.
            Log::warning('The wake request could not be removed', ['operation_id' => $operation->id, 'exception_type' => $exception::class]);
        }
    }
}
