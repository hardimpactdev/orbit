<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\Operation;

final readonly class DetachInstanceNodeServiceTargetAction
{
    public function __construct(private InstanceEnvironmentOperationLock $lock, private StartOperationAction $operations,
        private OperationIdempotency $idempotency, private InstanceContainer $containers) {}

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
            // Serialize withdrawals of shared Node endpoints, including the last user's removal.
            if (Operation::query()->where('kind', 'instance.node_service_detach')->where('node_id', $instance->node_id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('instance.lifecycle_busy', 'Another Node service withdrawal is running on this Node.', 409);
            }
            $result = [...AttachInstanceNodeServiceTargetAction::data($target, true)->toArray(), 'detached' => true];
            $input = ['instance_id' => $instance->id, 'target_id' => $target->id, 'attachment' => $result];
            $runtime = $instance->runtime()->first();
            if ($runtime === null) {
                $operation = $this->operations->record('instance.node_service_detach', 'instance', $instance->id, $instance->node_id,
                    $input, $result, $caller, $requestId, OperationStore::lockKey('instance', $instance->id));
                $target->delete();

                return $operation;
            }

            return $this->operations->execute('instance.node_service_detach', 'instance', $instance->id, $instance->node_id,
                [...$input, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
                    'container' => $this->containers->nodeServiceWithdrawal($instance, $runtime, $target)],
                $caller, $requestId, lockKey: OperationStore::lockKey('instance', $instance->id));
        });
    }
}
