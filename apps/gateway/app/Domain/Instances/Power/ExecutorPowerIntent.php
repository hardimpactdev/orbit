<?php

declare(strict_types=1);

namespace App\Domain\Instances\Power;

use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Executor\SignedExecutorOrder;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Executor\ExecutorResults;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use Illuminate\Support\Facades\Config;
use LogicException;

final class ExecutorPowerIntent
{
    public const string Managed = 'signed_power';

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
        if ($runtime->power_state !== InstanceRuntimePowerState::Stopped) {
            return null;
        }
        $sleep = Operation::query()->where('kind', InstanceSleep::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->where('status', OperationStatus::Succeeded)->latest('id')->first();

        return ($sleep?->result['sleep'] ?? null) === 'frozen' ? $sleep : null;
    }

    public static function action(Operation $operation, string $phase = 'power'): ?string
    {
        if ($operation->target_type !== 'instance') {
            return null;
        }
        $deployment = $operation->input['deployment'] ?? null;
        $removal = $operation->input['container_destroy'] ?? null;

        return match (true) {
            $phase === 'power' && self::managed($operation) && $operation->kind === InstanceSleep::Kind => 'sleep',
            $phase === 'power' && self::managed($operation) && $operation->kind === InstanceWake::Kind => 'wake',
            $operation->kind === InstanceStart::Kind && self::managed($operation),
            $operation->kind === 'instance.deploy' && is_array($deployment) && ($deployment[self::Managed] ?? false) === true => match ($phase) {
                'resume' => 'wake',
                'boot' => 'discard',
                default => null,
            },
            $phase === 'removal' && in_array($operation->kind, ['instance.destroy', 'instance.unregister'], true)
                && is_array($removal) && ($removal[self::Managed] ?? false) === true => 'discard',
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
            $action === 'wake' && is_array($container) && array_key_exists('web_config', $container) && $container['web_config'] === null => 'no_web',
            default => null,
        };

        return ExecutorOrderSigner::fromConfig()->sign($operation, $action, (string) $operation->target_id, $powerCheck);
    }

    /** @return array<string, mixed>|null */
    public static function lifecycleReceipt(Operation $operation, mixed $receipt): ?array
    {
        foreach (['resume', 'boot'] as $phase) {
            $action = self::action($operation, $phase);
            if ($action !== null && ($line = ExecutorResults::matchingReceipt($operation, $action, $receipt)) !== null) {
                return $line;
            }
        }

        return null;
    }
}
