<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceThreadContributions;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Str;

/**
 * `instance:sleep`: the command reads no T3 state, so its result says a turn was not checked (docs/cli/instance/sleep.mdx), and an
 * Instance without a T3 server says it has none. Its result names how the container sleeps: `frozen`, or `stopped` when swap did not
 * take its memory; `swap_subtree`, the number of inner cgroups the freeze opened, which only the wake's run closes again; and
 * `prepared_restore`, the power helper's plan of the sleep (docs/reference/prepared-restore.md#results).
 */
final readonly class InstanceSleep extends InstancePower
{
    public const string Kind = 'instance.sleep';

    /** The closed reason codes of a `skipped` or `unsupported` plan (docs/reference/prepared-restore.md#wake). */
    public const array PreparedReasons = ['no_plan', 'plan_invalid', 'plan_stale', 'unsupported', 'placement_unproven', 'bound_processes',
        'bound_ranges', 'bound_metadata', 'bound_residency', 'prepare_timeout', 'capture_failed', 'stopped'];

    /** @param  Closure(Instance): bool  $guard */
    public function startIf(Instance $instance, ?string $caller, ?string $requestId, Closure $guard): ?Operation
    {
        return $this->startWhen($instance, $caller, $requestId, $guard);
    }

    protected function kind(): string
    {
        return self::Kind;
    }

    protected function powerState(): InstanceRuntimePowerState
    {
        return InstanceRuntimePowerState::Stopped;
    }

    protected function admit(Instance $instance, InstanceRuntime $runtime, array $input, ?string $caller, ?string $requestId): Operation
    {
        if (! ExecutorPowerIntent::forInstance($instance, $runtime)) {
            return parent::admit($instance, $runtime, $input, $caller, $requestId);
        }
        $executor = app(ExecutorWake::class);
        $operation = $executor->admit($instance, [...$input, ExecutorPowerIntent::Managed => true], $caller, $requestId, self::Kind);
        if ($operation->wasRecentlyCreated) {
            $executor->dispatchDeferred($operation->id);
        }

        return $operation;
    }

    public function signedResult(Operation $operation, array $receipt): array
    {
        $measured = is_array($receipt['receipt'] ?? null) ? $receipt['receipt'] : [];
        $prepared = ['version' => 1, 'status' => $measured['plan'] ?? 'not_applicable'];
        if (is_string($measured['reason'] ?? null)) {
            $prepared['reason'] = $measured['reason'];
        }
        foreach (['processes' => 'processes', 'mapping_bytes' => 'resident_mapping_bytes', 'residual_anon_bytes' => 'residual_anon_bytes',
            'residual_file_bytes' => 'residual_file_bytes'] as $source => $key) {
            if (is_int($measured[$source] ?? null) && $measured[$source] >= 0) {
                $prepared[$key] = $measured[$source];
            }
        }

        return [...parent::signedResult($operation, $receipt), ...$this->kept($measured), 'prepared_restore' => $prepared];
    }

    protected function provesSignedSuccess(?array $receipt, array $phase): bool
    {
        return parent::provesSignedSuccess($receipt, $phase) && in_array($receipt['receipt']['sleep'] ?? null, ['frozen', 'stopped'], true);
    }

    protected function open(Instance $instance, bool $join): ?Operation
    {
        $operation = Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->first();

        return $operation !== null && ExecutorPowerIntent::managed($operation) ? $operation : null;
    }

    /** The extensions read the server's threads one last time, after the refusals and outside the Instance lock: a sleeping server cannot be read. */
    #[\Override]
    protected function beforeAdmission(Instance $instance): void
    {
        app(InstanceThreadContributions::class)->beforeSleep($instance);
    }

    protected function refuse(Instance $instance, InstanceRuntime $runtime): void
    {
        if (self::hasActiveTask($instance)) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'A task runs in the Instance.', 409);
        }
    }

    protected function result(Operation $operation): array
    {
        $server = InstanceRuntime::query()->where('instance_id', $operation->target_id)->value('t3_port') !== null;

        // The idle sweep read T3 and found no turn and no recent activity before it admitted the sleep.
        return [...parent::result($operation), 't3_turn' => match (true) {
            ! $server => 'no_server',
            $operation->triggered_by === ContainerIdleSweep::TriggeredBy => 'idle',
            default => 'not_checked',
        }];
    }

    protected function phaseKeys(): array
    {
        return ['sleep', 'swap_subtree'];
    }

    /**
     * The run's `prepared_restore` with only its documented fields, each of its type: a status and its reason, the plan's actual
     * measurements for `ready`, and the residual bytes. A value outside the contract is left out, never guessed.
     */
    #[\Override]
    protected function kept(array $phase): array
    {
        $kept = parent::kept($phase);
        $prepared = $phase['prepared_restore'] ?? null;
        if (! is_array($prepared)) {
            return $kept;
        }
        $status = $prepared['status'] ?? null;
        $reason = $prepared['reason'] ?? null;
        $count = static fn (mixed $value): bool => is_int($value) && $value >= 0;
        $seconds = $prepared['prepare_seconds'] ?? null;
        $ready = is_string($prepared['generation'] ?? null) && Str::isUuid($prepared['generation'])
            && $count($prepared['processes'] ?? null) && $count($prepared['resident_mapping_bytes'] ?? null)
            && $count($prepared['metadata_bytes'] ?? null) && (is_int($seconds) || is_float($seconds)) && $seconds >= 0;
        $projected = match (true) {
            $status === 'ready' && $ready => ['version' => 1, 'status' => 'ready', 'generation' => $prepared['generation'],
                'processes' => $prepared['processes'], 'resident_mapping_bytes' => $prepared['resident_mapping_bytes'],
                'metadata_bytes' => $prepared['metadata_bytes'], 'prepare_seconds' => (float) $seconds],
            in_array($status, ['unsupported', 'skipped'], true) && in_array($reason, self::PreparedReasons, true) => ['version' => 1,
                'status' => $status, 'reason' => $reason],
            $status === 'not_applicable' => ['version' => 1, 'status' => 'not_applicable'],
            default => null,
        };
        if ($projected === null) {
            return $kept;
        }
        foreach (['residual_anon_bytes', 'residual_file_bytes'] as $key) {
            if ($count($prepared[$key] ?? null)) {
                $projected[$key] = $prepared[$key];
            }
        }

        return [...$kept, 'prepared_restore' => $projected];
    }

    /** The Instance's newest sleep while its runtime records it stopped and that sleep succeeded: it sleeps behind its asleep marker. */
    public static function asleep(Instance $instance, InstanceRuntime $runtime): ?Operation
    {
        if ($runtime->power_state !== InstanceRuntimePowerState::Stopped) {
            return null;
        }
        $sleep = Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->orderByDesc('id')->first();

        return $sleep?->status === OperationStatus::Succeeded ? $sleep : null;
    }

    /**
     * The anonymous memory that may stay in RAM after the reclaim; more means swap is full, and the sleep stops the container. `route`
     * says whether a published Route has a Caddy site for the Instance: only such a site must check the asleep marker before the freeze,
     * and an Instance without one, such as a default without a Route, sleeps with nothing to check.
     */
    protected function intent(Instance $instance): array
    {
        return ['freeze' => ['resident_anon_mib' => 32], 'route' => $instance->routes()->where('sites_published', true)->exists()];
    }
}
