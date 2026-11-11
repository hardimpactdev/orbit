<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\InstanceNodeServiceTargetData;
use App\Domain\InstanceMigration\InstanceMigration;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceNodeServiceTargets;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Database\Eloquent\Builder;

final readonly class AttachInstanceNodeServiceTargetAction
{
    public function __construct(private InstanceEnvironmentOperationLock $operations) {}

    public function execute(Instance $instance, int $processId, string $protocol, ?int $port, string $hostEnvKey, string $portEnvKey): InstanceNodeServiceTargetData
    {
        return $this->operations->run([$instance->id], function () use ($instance, $processId, $protocol, $port, $hostEnvKey, $portEnvKey): InstanceNodeServiceTargetData {
            $instance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            self::assertMutable($instance);
            $port = InstanceNodeServiceTargets::resolvePort($instance, Process::query()->find($processId), $protocol, $port);
            $nodeId = $instance->node_id;
            $target = new InstanceNodeServiceTarget(['instance_id' => $instance->id, 'process_id' => $processId, 'node_id' => $nodeId, 'protocol' => $protocol,
                'port' => $port, 'host_env_key' => $hostEnvKey, 'port_env_key' => $portEnvKey]);
            $existing = $instance->nodeServiceTargets()->where('host_env_key', $hostEnvKey)->where('port_env_key', $portEnvKey)->first();
            if ($existing instanceof InstanceNodeServiceTarget && $existing->process_id === $processId && $existing->node_id === $nodeId && $existing->protocol === $protocol && $existing->port === $port) {
                return self::data($existing, false);
            }
            if ($instance->nodeServiceTargets()->where(static fn (Builder $query): Builder => $query
                ->whereIn('host_env_key', [$hostEnvKey, $portEnvKey])->orWhereIn('port_env_key', [$hostEnvKey, $portEnvKey]))->exists()) {
                throw new ResourceOperationException('instance.node_service_environment_conflict', 'Another Node service target owns one of these environment keys. Detach it before attaching a correction.', 409);
            }
            $target->save();

            return self::data($target, true);
        });
    }

    public static function assertMutable(Instance $instance, bool $detaching = false): void
    {
        if (InstanceMigration::running($instance) || $instance->status !== InstanceState::Active
            || Operation::query()->where('kind', 'instance.node_service_detach')->where('target_type', 'instance')->where('target_id', $instance->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            throw new ResourceOperationException('instance.lifecycle_busy', 'The Instance is busy with another lifecycle operation.', 409);
        }
        if ($instance->runtime()->exists()) {
            if ($detaching && InstanceMigration::pending($instance)) {
                throw new ResourceOperationException('instance.node_service_runtime_cleanup_required',
                    'A failed migration retained a runtime that may still own access. Retry instance:migrate to finish or restore it before detaching.', 409);
            }
            if (! $detaching) {
                throw new ResourceOperationException('instance.node_service_runtime_unsupported', 'Attach Node service targets before migrating the checkout Instance.', 422);
            }
        }
    }

    public static function data(InstanceNodeServiceTarget $target, bool $changed): InstanceNodeServiceTargetData
    {
        return new InstanceNodeServiceTargetData($target->id, $target->instance_id, $target->node_id, $target->process_id, $target->protocol,
            $target->port, $target->host_env_key, $target->port_env_key, $changed, $changed ? null : 'instance.node_service_target_unchanged');
    }
}
