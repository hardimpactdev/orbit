<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\RemovalLifecycleExecution;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\Operation;

/** Returns the retained removal receipt only after the exact admitted Operation proves completion. */
final readonly class AwaitInstanceRemovalAction
{
    public function __construct(private AwaitOperation $operations) {}

    public function execute(Operation $operation, int $timeoutSeconds = 30): InstanceRemoval
    {
        if ($operation->kind !== 'instance.destroy' || $operation->target_type !== 'instance'
            || $operation->target_id !== ($operation->input['instance_id'] ?? null)) {
            throw $this->unproven($operation);
        }
        $operation = $this->operations->execute($operation, $timeoutSeconds);
        if ($operation->status !== OperationStatus::Succeeded) {
            throw new ResourceOperationException($operation->error_code ?? 'operation.cancelled',
                'The Instance removal did not succeed.', 409, details: ['operation_id' => $operation->id]);
        }
        $intent = app(RemovalLifecycleExecution::class)->intent($operation);
        $id = $intent['removal_id'] ?? null;
        if (! is_string($id) || ($operation->result['removal_id'] ?? null) !== $id
            || ($operation->result['instance_id'] ?? null) !== $operation->target_id
            || ($operation->result['lifecycle_complete'] ?? null) !== true) {
            throw $this->unproven($operation);
        }
        $removal = InstanceRemoval::query()->with('members')->find($id);
        if ($removal === null || $removal->requested_instance_id !== $operation->target_id
            || $removal->status !== InstanceRemovalStatus::Completed
            || $removal->total < 1 || $removal->members->count() !== $removal->total
            || $removal->members->contains(static fn (InstanceRemovalMember $member): bool => $member->row_deleted_at === null)
            || Instance::query()->whereKey($removal->members->pluck('instance_id'))->exists()) {
            throw $this->unproven($operation);
        }

        return $removal;
    }

    private function unproven(Operation $operation): ResourceOperationException
    {
        return new ResourceOperationException('operation.completion_unproven',
            'The Operation does not prove this Instance removal.', 409, details: ['operation_id' => $operation->id]);
    }
}
