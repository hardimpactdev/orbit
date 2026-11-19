<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Support\Facades\Config;
use LogicException;

final class ExecutorPowerIntent
{
    public const string Managed = 'signed_power';

    public const string LifecyclePhase = 'executor_lifecycle_phase';

    public static function enabled(): bool
    {
        return Config::boolean('orbit.executor.power_enabled', false);
    }

    public static function managed(Operation $operation): bool
    {
        return ($operation->input[self::Managed] ?? false) === true;
    }

    public static function forInstance(Instance $instance, InstanceRuntime $runtime): bool
    {
        if (($sleep = self::frozenSleep($instance, $runtime)) !== null) {
            return self::managed($sleep);
        }

        return self::enabled();
    }

    public static function frozenSleep(Instance $instance, InstanceRuntime $runtime): ?Operation
    {
        return $runtime->power_state === InstanceRuntimePowerState::Stopped ? ContainerResume::frozenSleep($instance) : null;
    }

    /** @return array{signed_power?: true, executor_lifecycle_phase?: 'resume'|'boot'} */
    public static function lifecycle(Instance $instance, InstanceRuntime $runtime): array
    {
        $sleep = self::frozenSleep($instance, $runtime);
        if ($sleep === null ? ! self::enabled() : ! self::managed($sleep)) {
            return [];
        }

        return [self::Managed => true, self::LifecyclePhase => $sleep === null ? 'boot' : 'resume'];
    }

    public static function action(Operation $operation, string $phase = 'power'): ?string
    {
        if ($operation->target_type !== 'instance') {
            return null;
        }
        $deployment = $operation->input['deployment'] ?? null;
        $lifecycle = match ($operation->kind) {
            InstanceStart::Kind => $operation->input,
            'instance.deploy' => is_array($deployment) ? $deployment : [],
            default => [],
        };
        $admittedPhase = $lifecycle[self::LifecyclePhase] ?? null;

        return match (true) {
            $phase === 'power' && self::managed($operation) && $operation->kind === InstanceSleep::Kind => 'sleep',
            $phase === 'power' && self::managed($operation) && $operation->kind === InstanceWake::Kind => 'wake',
            ($lifecycle[self::Managed] ?? false) === true && $admittedPhase === $phase => match ($admittedPhase) {
                'resume' => 'wake',
                'boot' => 'discard',
                default => null,
            },
            default => null,
        };
    }

    public static function sign(Operation $operation, string $phase = 'power'): SignedExecutorOrder
    {
        $action = self::action($operation, $phase) ?? throw new LogicException('This Operation has no signed power intent.');
        $deployment = $operation->input['deployment'] ?? null;
        $container = $operation->input['container'] ?? (is_array($deployment) ? ($deployment['container'] ?? null) : null);
        $powerCheck = match (true) {
            $action === 'sleep' && ($operation->input['route'] ?? false) === true => 'route',
            $action === 'wake' && $phase === 'resume' => 'resume',
            $action === 'wake' && is_array($container) && array_key_exists('web_config', $container) && $container['web_config'] === null => 'no_web',
            default => null,
        };

        // A wake that probes the app waits the intent's `web.ready_seconds` for it; an order without it gets the executor's 30 s.
        $ready = data_get($operation->input, 'web.ready_seconds');
        $input = $action === 'wake' && $powerCheck === null && is_int($ready) ? ['web' => ['ready_seconds' => $ready]] : [];

        return ExecutorOrderSigner::fromConfig()->sign($operation, $action, (string) $operation->target_id, $powerCheck, $input);
    }

    /** @return array<string, mixed>|null The lifecycle phase's receipt, recorded once on the Operation (ADR 0266, R1). */
    public static function lifecycleReceipt(Operation $operation, mixed $receipt): ?array
    {
        foreach (['resume', 'boot'] as $phase) {
            $action = self::action($operation, $phase);
            if ($action !== null && ($line = ExecutorFence::record($operation, $action, $receipt)) !== null) {
                return $line;
            }
        }

        return null;
    }

    /**
     * R1 for a lifecycle run (a start or a deploy), whatever the run reported: a failed receipt fails it with the receipt's code, a
     * successful one leaves the wrapper's own outcome, and a run that reported success needs a receipt for an issued order or a resume
     * (R2). A boot whose run issued no order needs none, such as an already-running container. Returns the error, null for success.
     *
     * @param  array<string, mixed>|null  $receipt
     */
    public static function lifecycleError(Operation $operation, ?array $receipt, bool $ran, ?string $runError, bool $verifiedBoot): ?string
    {
        if ($receipt === null) {
            return $ran && (ExecutorFence::issued($operation) || self::action($operation, 'resume') !== null) ? 'executor.result_unproven' : $runError;
        }

        return ExecutorFence::verdict($receipt, $ran, $runError, $verifiedBoot, workAfter: true)['error_code'];
    }
}
