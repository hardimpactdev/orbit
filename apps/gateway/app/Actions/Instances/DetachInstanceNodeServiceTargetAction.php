<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\NodeServiceTargetOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\Operation;

final readonly class DetachInstanceNodeServiceTargetAction
{
    public function __construct(private InstanceEnvironmentOperationLock $lock, private OperationStore $operations,
        private OperationIdempotency $idempotency) {}

    public function execute(Instance $instance, int $targetId, ?string $caller = null, ?string $requestId = null): Operation
    {
        if (($replay = $this->idempotency->replay('instance.node_service_detach')) !== null) {
            return $replay;
        }

        return $this->lock->run([$instance->id], function () use ($instance, $targetId, $caller, $requestId): Operation {
            $instance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            AttachInstanceNodeServiceTargetAction::assertMutable($instance, detaching: true);
            $target = $instance->nodeServiceTargets()->whereKey($targetId)->first();
            if (! $target instanceof InstanceNodeServiceTarget) {
                throw new ResourceOperationException('instance.node_service_target_missing', 'The Node service target is not attached to this Instance.', 404);
            }
            if ($instance->runtime()->exists()) {
                return app(NodeServiceTargetOperation::class)->start($instance, $target, $caller, $requestId);
            }
            $result = [...AttachInstanceNodeServiceTargetAction::data($target, true)->toArray(), 'detached' => true];
            $input = ['instance_id' => $instance->id, 'target_id' => $target->id, 'attachment' => $result];
            $operation = $this->operations->create('instance.node_service_detach', 'instance', $instance->id, $instance->node_id,
                $input, $caller, $requestId, OperationStore::lockKey('instance', $instance->id));
            if ($operation->wasRecentlyCreated) {
                $this->operations->change($operation, ['status' => OperationStatus::Succeeded, 'result' => $result,
                    'started_at' => now(), 'finished_at' => now(), 'lock_key' => null]);
                $target->delete();
            }

            return $operation;
        });
    }
}
