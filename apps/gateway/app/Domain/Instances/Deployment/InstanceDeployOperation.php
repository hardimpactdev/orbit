<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\DeployInstanceAction;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\OperationKind;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRun;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;

/**
 * The `instance.deploy` operation (docs/reference/deployments.md#the-deploy-operation). It records one deployment
 * history row on its first attempt, runs the deployment's steps as the operation's steps, and saves the row's events
 * after each step, so a resumed attempt continues the same row. An input `commit` pins the deployment to that commit;
 * a failed deployment then records it too.
 */
final readonly class InstanceDeployOperation implements OperationKind
{
    public function __construct(
        private DeployInstanceAction $deploy,
        private InstanceDeploymentRecorder $recorder,
        private InstanceEnvironmentOperationLock $locks,
        private OperationDeploymentHistory $history,
        private AutomaticDeployPolicyWriter $writer,
    ) {}

    public function run(Operation $operation, OperationRun $run): OperationOutcome
    {
        $instance = Instance::query()->find($operation->target_id);

        if (! $instance instanceof Instance) {
            return OperationOutcome::failed('operation.target_missing');
        }

        if (! in_array($operation->triggered_by, AutomaticDeployHistory::Triggers, true) || (! $instance->placedOnAppProd() && ! isset($operation->input['caller_node_id']))) {
            return $this->deploy($operation, $run, $instance);
        }

        // The tick read the history before it started this operation. A rollback or manual deployment that ran in
        // between left its row, so the check under the Instance lock finds it, and the pause wins. While this
        // operation holds the Instance, no other deployment starts, and a rollback cancels it before a worker starts it.
        try {
            return $this->locks->run([$instance->id], function () use ($operation, $run, $instance): OperationOutcome {
                $this->writer->assertSwamp($instance->refresh());
                $history = new AutomaticDeployHistory($instance);
                if ($history->pausedBy() instanceof InstanceDeployment || (isset($operation->input['caller_node_id']) && ! $instance->auto_deploy)) {
                    return $this->paused($operation);
                }
                $commit = self::commit($operation);
                if ($commit !== null && in_array($commit, [...$history->rolledAway(), ...AutomaticDeployState::of($instance)->blocked()], true)) {
                    return $this->paused($operation, 'deployment.commit_blocked');
                }

                return $this->deploy($operation, $run, $instance);
            });
        } catch (ResourceOperationException $exception) {
            return in_array($exception->errorCode, [AutomaticDeployHistory::Paused, 'instance.auto_deploy_policy_owner', AutomaticDeployPolicyWriter::InactiveRole], true)
                ? $this->paused($operation, $exception->errorCode)
                : OperationOutcome::failed($exception->errorCode);
        }
    }

    /**
     * Ends an automatic deployment that a pause stopped before it deployed. A first attempt writes no history row; a
     * resumed attempt ends its row with `deployment.paused`, which counts as no failure of the commit.
     */
    private function paused(Operation $operation, string $errorCode = AutomaticDeployHistory::Paused): OperationOutcome
    {
        $deployment = $this->history->stored($operation);

        if ($deployment instanceof InstanceDeployment && $deployment->status === 'running') {
            $this->recorder->finish(
                $deployment,
                DeploymentResult::failed(null, null, DeploymentFailureBoundary::Operation, $errorCode, commit: self::commit($operation)),
                $deployment->events ?? [],
            );
        }

        return OperationOutcome::failed($errorCode);
    }

    private function deploy(Operation $operation, OperationRun $run, Instance $instance): OperationOutcome
    {
        $deployment = $this->history->row($operation, $run, $instance, 'deploy');
        $commit = self::commit($operation);

        try {
            $plan = $this->deploy->plan($instance);
        } catch (ResourceOperationException $exception) {
            $result = DeploymentResult::failed(null, null, DeploymentFailureBoundary::Operation, $exception->errorCode, commit: $commit);

            return $this->history->finish($deployment, $result, $deployment->events ?? []);
        }

        $restartProcesses = ! $operation->steps()->exists()
            || $operation->steps()->where('name', DeploymentProgressPhase::ProcessRestart->value)->exists();
        if (! $restartProcesses) {
            $plan = array_values(array_filter($plan, static fn (DeploymentStepName $step): bool => $step->phase !== DeploymentProgressPhase::ProcessRestart));
        }

        $this->history->plan($run, $plan);

        return $this->history->run(
            $deployment,
            $run,
            fn (DeploymentSteps $steps, DeploymentRequest $request): DeploymentResult => $this->deploy->execute($instance, $steps, $request, $commit),
            $restartProcesses,
        );
    }

    public function abandon(Operation $operation, OperationOutcome $failure, ?string $failedStep): OperationOutcome
    {
        $steps = is_array($operation->state['steps'] ?? null) ? $operation->state['steps'] : [];
        $prepared = is_array($steps['source_preparation'] ?? null) ? $steps['source_preparation'] : [];
        $selected = DeployInstanceAction::storedRelease($steps['activation'] ?? null)
            ?? DeployInstanceAction::storedRelease($prepared['previous'] ?? null);

        return $this->history->abandon(
            $operation,
            $failure,
            $failedStep,
            DeployInstanceAction::storedRelease($prepared['release'] ?? null),
            $selected,
            self::commit($operation),
        );
    }

    /** The commit the operation deploys, or null for the branch's newest commit. */
    public static function commit(Operation $operation): ?string
    {
        $commit = $operation->input['commit'] ?? null;

        return is_string($commit) && GitCommitSha::isValid($commit) ? $commit : null;
    }
}
