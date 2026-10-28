<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** A restart cannot reset the admitted deployment's total time budget. */
final readonly class InstanceDeploymentBudget
{
    public function remaining(Operation $operation): int
    {
        $started = $operation->started_at ?? throw new ResourceOperationException('operation.completion_unproven', 'Claim the deployment before preparing effects.', 409);
        $intent = InstanceDeploymentIntent::admitted($operation);
        $seconds = min(OperationRunner::DeadlineSeconds, DeploymentDeadline::InfrastructureSeconds
            + array_sum(array_column($intent['steps'], 'timeout_seconds')));

        return max(0, $started->getTimestamp() + $seconds - now()->getTimestamp());
    }

    public function requireRemaining(Operation $operation): int
    {
        $remaining = $this->remaining($operation);
        if ($remaining === 0) {
            throw new ResourceOperationException('deployment.deadline_exceeded', 'The deployment deadline expired before this effect started.', 504);
        }

        return $remaining;
    }
}
