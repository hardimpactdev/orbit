<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\RollbackInstanceAction;
use App\Domain\Operations\OperationKind;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;

/**
 * The `instance.rollback` operation (docs/reference/deployments.md#the-rollback-operation). It records one rollback
 * history row on its first attempt, runs the rollback's steps as the operation's steps, and saves the row's events after
 * each step, so a resumed attempt continues the same row. The input `release` names the retained release to select.
 */
final readonly class InstanceRollbackOperation implements OperationKind
{
    public function __construct(
        private RollbackInstanceAction $rollback,
        private OperationDeploymentHistory $history,
    ) {}

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $instance = Instance::query()->find($operation->target_id);

        if (! $instance instanceof Instance) {
            return OperationOutcome::failed('operation.target_missing');
        }

        $deployment = $this->history->row($operation, $run, $instance, InstanceDeployment::Rollback);

        try {
            $plan = $this->rollback->plan($instance);
        } catch (ResourceOperationException $exception) {
            $result = DeploymentResult::failed(null, null, DeploymentFailureBoundary::Operation, $exception->errorCode);

            return $this->history->finish($deployment, $result, $deployment->events ?? []);
        }

        $this->history->plan($run, $plan);
        $release = $operation->input['release'] ?? null;
        $release = is_string($release) ? $release : '';

        return $this->history->run(
            $deployment,
            $run,
            fn (DeploymentSteps $steps, DeploymentRequest $request): DeploymentResult => $this->rollback->execute($instance, $release, $steps, $request),
        );
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $steps = is_array($operation->state['steps'] ?? null) ? $operation->state['steps'] : [];
        $found = is_array($steps['rollback'] ?? null) ? $steps['rollback'] : [];
        $selected = DeployInstanceAction::storedRelease($steps['activation'] ?? null)
            ?? DeployInstanceAction::storedRelease($found['previous'] ?? null);

        return $this->history->abandon(
            $operation,
            $failure,
            $failedStep,
            DeployInstanceAction::storedRelease($found['release'] ?? null),
            $selected,
            null,
        );
    }
}
