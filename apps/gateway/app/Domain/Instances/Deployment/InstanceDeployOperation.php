<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\DeployInstanceAction;
use App\Domain\Operations\OperationKind;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use InvalidArgumentException;

/**
 * The `instance.deploy` operation (docs/reference/deployments.md#the-deploy-operation). It records one deployment
 * history row on its first attempt, runs the deployment's steps as the operation's steps, and saves the row's events
 * after each step, so a resumed attempt continues the same row.
 */
final readonly class InstanceDeployOperation implements OperationKind
{
    public function __construct(
        private DeployInstanceAction $deploy,
        private InstanceDeploymentRecorder $recorder,
    ) {}

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $instance = Instance::query()->find($operation->target_id);

        if (! $instance instanceof Instance) {
            return OperationOutcome::failed('operation.target_missing');
        }

        $deployment = $this->deployment($operation, $run, $instance);

        try {
            $plan = $this->deploy->plan($instance);
        } catch (ResourceOperationException $exception) {
            $result = DeploymentResult::failed(null, null, DeploymentFailureBoundary::Operation, $exception->errorCode);
            $this->recorder->finish($deployment, $result, $deployment->events ?? []);

            return $this->outcome($deployment, $result);
        }

        $run->plan(array_map(
            static fn (DeploymentStepName $step): OperationStepPlan => new OperationStepPlan($step->name(), $step->resumable()),
            $plan,
        ));

        $collector = DeploymentEventCollector::resume($deployment->events ?? []);
        $request = new DeploymentRequest(
            output: static function (DeploymentEvent $event) use ($collector, $run): void {
                $collector->output($event);
                $run->output($event->value);
            },
            phase: static function (DeploymentProgressPhase $phase, ?string $stepName) use ($collector): void {
                $collector->phase($phase, $stepName);
            },
        );
        $steps = new OperationDeploymentSteps($run, static function () use ($deployment, $collector): void {
            $deployment->update(['events' => $collector->events()]);
        });

        $result = $this->deploy->execute($instance, $steps, $request);
        $this->recorder->finish($deployment, $result, $collector->events());

        return $this->outcome($deployment, $result);
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $deploymentId = $operation->state['deployment_id'] ?? null;
        $deployment = is_int($deploymentId) ? InstanceDeployment::query()->find($deploymentId) : null;

        if (! $deployment instanceof InstanceDeployment) {
            return $failure;
        }

        $steps = is_array($operation->state['steps'] ?? null) ? $operation->state['steps'] : [];
        $prepared = is_array($steps['source_preparation'] ?? null) ? $steps['source_preparation'] : [];
        $release = DeployInstanceAction::storedRelease($prepared['release'] ?? null);
        $selected = DeployInstanceAction::storedRelease($steps['activation'] ?? null)
            ?? DeployInstanceAction::storedRelease($prepared['previous'] ?? null);
        $result = DeploymentResult::failed(
            $release,
            $selected,
            $this->boundary($failedStep),
            $failure->status === OperationStatus::Cancelled ? 'deployment.cancelled' : ($failure->errorCode ?? 'deployment.interrupted'),
        );

        if ($deployment->status === 'running') {
            $this->recorder->finish($deployment, $result, $deployment->events ?? []);
        }

        return $failure->status === OperationStatus::Cancelled
            ? OperationOutcome::cancelled($this->result($deployment, $result))
            : OperationOutcome::failed($failure->errorCode ?? 'operation.failed', $this->result($deployment, $result));
    }

    /** The deployment row of this operation: created on the first attempt and kept in the operation's state. */
    private function deployment(Operation $operation, OperationRun $run, Instance $instance): InstanceDeployment
    {
        $deploymentId = $run->remember('deployment_id', fn (): int => $this->recorder->start($instance, $operation->triggered_by ?? 'operation')->id);
        $deployment = is_int($deploymentId) ? InstanceDeployment::query()->find($deploymentId) : null;

        return $deployment instanceof InstanceDeployment
            ? $deployment
            : throw new InvalidArgumentException('The deployment row of the operation is missing.');
    }

    private function outcome(InstanceDeployment $deployment, DeploymentResult $result): OperationOutcome
    {
        $data = $this->result($deployment, $result);

        if ($result->succeeded) {
            return OperationOutcome::succeeded($data);
        }

        $errorCode = $result->failure->errorCode ?? 'deployment.interrupted';

        return $errorCode === 'deployment.cancelled'
            ? OperationOutcome::cancelled($data)
            : OperationOutcome::failed($errorCode, $data);
    }

    /** @return array{deployment_id: int, release: string|null, selected_release: string|null, commit: string|null} */
    private function result(InstanceDeployment $deployment, DeploymentResult $result): array
    {
        return [
            'deployment_id' => $deployment->id,
            'release' => $result->release?->name,
            'selected_release' => $result->selectedRelease?->name,
            'commit' => $result->commit,
        ];
    }

    private function boundary(?string $failedStep): DeploymentFailureBoundary
    {
        if ($failedStep === null) {
            return DeploymentFailureBoundary::Operation;
        }

        try {
            return DeploymentStepName::fromName($failedStep)->boundary();
        } catch (InvalidArgumentException) {
            return DeploymentFailureBoundary::Operation;
        }
    }
}
