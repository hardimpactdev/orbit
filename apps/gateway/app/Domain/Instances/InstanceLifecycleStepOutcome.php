<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Reads a bound native list outcome already validated by its family projector. */
final readonly class InstanceLifecycleStepOutcome
{
    /** @return array{outcome:string,error_code:?string,failed_step:?string} */
    public function read(Operation $child): array
    {
        if ($child->kind !== 'instance.run_steps') {
            throw new ResourceOperationException('operation.child_unproven', 'Lifecycle outcomes require a step-list child.', 409);
        }
        $unknown = ['outcome' => 'unconfirmed', 'error_code' => 'instance.lifecycle_unconfirmed', 'failed_step' => null];
        if (! DirectOperation::owns($child) || ! is_string($child->state['swamp_run_id'] ?? null)) {
            return $unknown;
        }
        if ($child->status === OperationStatus::Cancelled) {
            return ['outcome' => 'cancelled', 'error_code' => 'operation.cancelled', 'failed_step' => null];
        }
        $result = $child->result ?? [];
        if (($result['instance_id'] ?? null) !== $child->target_id) {
            return $unknown;
        }
        if ($child->status === OperationStatus::Succeeded && ($result['outcome'] ?? null) === 'succeeded') {
            return ['outcome' => 'succeeded', 'error_code' => null, 'failed_step' => null];
        }
        if ($child->status === OperationStatus::Failed && in_array($result['outcome'] ?? null, ['failed', 'missing', 'busy', 'deadline'], true)
            && is_string($result['error_code'] ?? null) && ($result['error_code'] ?? null) === $child->error_code) {
            return ['outcome' => $result['outcome'], 'error_code' => $result['error_code'], 'failed_step' => $result['failed_step'] ?? null];
        }

        return $unknown;
    }
}
