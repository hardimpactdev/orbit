<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStepPlan;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use Closure;
use InvalidArgumentException;

/**
 * The deployment history row of an `instance.deploy` or `instance.rollback` operation
 * (docs/reference/deployments.md#history). The first attempt creates the row and keeps its ID in the operation's state,
 * so a resumed attempt continues the same row. The row's events are saved after each step, and the run's result
 * finishes the row and becomes the operation's outcome.
 */
final readonly class OperationDeploymentHistory
{
    public function __construct(private InstanceDeploymentRecorder $recorder) {}

    /** The row of this operation: created on the first attempt and kept in the operation's state. */
    public function row(Operation $operation, OperationRun $run, Instance $instance, string $kind): InstanceDeployment
    {
        $deploymentId = $run->remember('deployment_id', fn (): int => $this->recorder->start($instance, $operation->triggered_by ?? 'operation', $kind, $operation->callerNodeId())->id);
        $deployment = is_int($deploymentId) ? InstanceDeployment::query()->find($deploymentId) : null;

        return $deployment instanceof InstanceDeployment
            ? $deployment
            : throw new InvalidArgumentException('The deployment row of the operation is missing.');
    }

    /** The row that an earlier attempt of this operation created, or null. */
    public function stored(Operation $operation): ?InstanceDeployment
    {
        $deploymentId = $operation->state['deployment_id'] ?? null;
        $deployment = is_int($deploymentId) ? InstanceDeployment::query()->find($deploymentId) : null;

        return $deployment instanceof InstanceDeployment ? $deployment : null;
    }

    /**
     * Records the named steps as the operation's steps.
     *
     * @param  list<DeploymentStepName>  $plan
     */
    public function plan(OperationRun $run, array $plan): void
    {
        $run->plan(array_map(
            static fn (DeploymentStepName $step): OperationStepPlan => new OperationStepPlan($step->name(), $step->resumable()),
            $plan,
        ));
    }

    /**
     * Runs the work through the operation's steps. Its output goes to the running step and to the row's events. Then
     * the result finishes the row and becomes the operation's outcome.
     *
     * @param  Closure(DeploymentSteps, DeploymentRequest): DeploymentResult  $work
     */
    public function run(InstanceDeployment $deployment, OperationRun $run, Closure $work, bool $restartProcesses = true): OperationOutcome
    {
        $collector = DeploymentEventCollector::resume($deployment->events ?? []);
        $request = new DeploymentRequest(
            output: static function (DeploymentEvent $event) use ($collector, $run): void {
                $collector->output($event);
                $run->output($event->value);
            },
            phase: static function (DeploymentProgressPhase $phase, ?string $stepName) use ($collector): void {
                $collector->phase($phase, $stepName);
            },
            operation: $run->operation()->id,
        );
        $steps = new OperationDeploymentSteps($run, static function () use ($deployment, $collector): void {
            $deployment->update(['events' => $collector->events()]);
        }, restartProcesses: $restartProcesses);

        return $this->finish($deployment, $work($steps, $request), $collector->events());
    }

    /**
     * Finishes the row with the result and returns the operation's outcome.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public function finish(InstanceDeployment $deployment, DeploymentResult $result, array $events): OperationOutcome
    {
        $this->recorder->finish($deployment, $result, $events);
        $data = self::result($deployment, $result);

        if ($result->succeeded) {
            return OperationOutcome::succeeded($data);
        }

        $errorCode = $result->failure->errorCode ?? 'deployment.interrupted';

        return $errorCode === 'deployment.cancelled'
            ? OperationOutcome::cancelled($data)
            : OperationOutcome::failed($errorCode, $data);
    }

    /**
     * Finishes the row of an operation that the runner ends with this failure, at the boundary of `$failedStep`, and
     * returns the outcome to store. An operation without a row keeps the failure.
     */
    public function abandon(
        Operation $operation,
        OperationOutcome $failure,
        ?string $failedStep,
        ?DeploymentRelease $release,
        ?DeploymentRelease $selected,
        ?string $commit,
    ): OperationOutcome {
        $deployment = $this->stored($operation);

        if (! $deployment instanceof InstanceDeployment) {
            return $failure;
        }

        $result = DeploymentResult::failed(
            $release,
            $selected,
            self::boundary($failedStep),
            $failure->status === OperationStatus::Cancelled ? 'deployment.cancelled' : ($failure->errorCode ?? 'deployment.interrupted'),
            commit: $commit,
        );

        if ($deployment->status === 'running') {
            $this->recorder->finish($deployment, $result, $deployment->events ?? []);
        }

        return $failure->status === OperationStatus::Cancelled
            ? OperationOutcome::cancelled(self::result($deployment, $result))
            : OperationOutcome::failed($failure->errorCode ?? 'operation.failed', self::result($deployment, $result));
    }

    /** @return array{deployment_id: int, release: string|null, selected_release: string|null, commit: string|null} */
    private static function result(InstanceDeployment $deployment, DeploymentResult $result): array
    {
        return [
            'deployment_id' => $deployment->id,
            'release' => $result->release?->name,
            'selected_release' => $result->selectedRelease?->name,
            'commit' => $result->commit,
        ];
    }

    private static function boundary(?string $failedStep): DeploymentFailureBoundary
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
